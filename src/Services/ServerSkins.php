<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Kraft\Games\PlayerUuid;
use App\Kraft\Link\Contracts\PollHandler;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Skins\Models\Profile;
use KraftInk\Plugins\Skins\Models\ServerIntegration;
use KraftInk\Plugins\Skins\Models\Synchronization;

class ServerSkins implements PollHandler
{
    private const int INTEGRATION_FRESH_SECONDS = 360;

    private const int RETRY_SECONDS = 300;

    public function __construct(private Profiles $profiles)
    {
    }

    public function serverSyncEnabled(Server $server): bool
    {
        return (bool) ServerIntegration::query()->whereKey($server->id)->value('sync_enabled');
    }

    public function setServerSyncEnabled(Server $server, bool $enabled): void
    {
        DB::transaction(function () use ($server, $enabled): void {
            Server::query()->whereKey($server->id)->where('game', 'minecraft')->lockForUpdate()->firstOrFail(['id']);
            ServerIntegration::query()->updateOrCreate(['server_id' => $server->id], ['sync_enabled' => $enabled]);
        });
    }

    public function integrationState(Server $server): string
    {
        if (!$server->enabled || $server->game !== 'minecraft') {
            return 'inactive';
        }
        if ($server->connection !== 'kraftlink') {
            return 'wrong_transport';
        }
        if (!$server->token_hash) {
            return 'unlinked';
        }
        $platform = $server->status['connector_platform'] ?? null;
        if ($platform !== null && $platform !== 'bukkit') {
            return 'unsupported_platform';
        }
        $integration = ServerIntegration::query()->whereKey($server->id)
            ->where('connector_token_hash', $server->token_hash)->first();
        if ($integration !== null) {
            return $integration->polled_at->gte(now()->subSeconds(self::INTEGRATION_FRESH_SECONDS)) ? 'ready' : 'stale';
        }
        if ($platform === null) {
            return 'awaiting_platform';
        }

        return $server->hasFreshStatus() ? 'bukkit_reported' : 'stale';
    }

    public function poll(Server $server, array $payload): array
    {
        $this->profiles->ensureMinecraft();
        abort_unless(
            $server->game === 'minecraft' && $server->connection === 'kraftlink' && $server->enabled &&
            request()->attributes->get('link.connector_platform') === 'bukkit',
            403,
        );

        $data = Validator::make($payload, [
            'players' => ['present', 'array', 'list', 'max:40'],
            'players.*.uuid' => ['required', 'string', 'max:36'],
            'players.*.name' => ['sometimes', 'string', 'max:16'],
            'results' => ['sometimes', 'array', 'list', 'max:16'],
            'results.*.uuid' => ['required', 'string', 'max:36'],
            'results.*.revision' => ['required', 'uuid'],
            'results.*.status' => ['required', Rule::in(['applied', 'error', 'offline'])],
        ])->validate();

        $this->recordPoll($server);
        if (!$this->profiles->gameSyncEnabled()) {
            return ['skins' => []];
        }

        foreach ($data['results'] ?? [] as $result) {
            $this->complete($server, $result);
        }

        $players = array_values(array_unique(array_filter(array_map(
            fn (array $player) => Profiles::javaUuid($player['uuid']),
            $data['players'],
        ))));
        if ($players === []) {
            return ['skins' => []];
        }

        $enabled = $this->serverSyncEnabled($server);
        $this->reconcile($server, $players, $enabled);
        $jobs = Synchronization::query()->forConnector($server)->onCurrentConnector()->pending()
            ->whereHas('profile', fn (Builder $query) => $query->withCurrentIdentity()->whereIn('minecraft_uuid', $players))
            ->with('profile')->orderBy('updated_at')->orderBy('id')->limit(16)->get();
        $skins = [];
        $bytes = 0;
        $hasPng = false;
        foreach ($jobs as $job) {
            if ($job->action === 'upload' && $hasPng) {
                continue;
            }
            $texture = $this->payload($server, $job, $enabled);
            if ($texture === null) {
                continue;
            }
            $size = strlen(json_encode($texture, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes + $size > 60_000) {
                continue;
            }
            if (!$this->markDispatched($server, $job)) {
                continue;
            }

            $bytes += $size;
            $hasPng = $hasPng || isset($texture['png']);
            $skins[] = $texture;
        }
        abort_if($skins !== [] && !$this->currentConnector($server)->exists(), 401);

        return ['skins' => $skins];
    }

    private function complete(Server $server, array $result): void
    {
        $uuid = Profiles::javaUuid($result['uuid']);
        if ($uuid === null || $result['status'] === 'offline') {
            return;
        }

        $query = Synchronization::query()->forConnector($server)->onCurrentConnector()->pending()
            ->where('revision', $result['revision'])->whereColumn('last_auto_fingerprint', 'target_fingerprint')
            ->whereHas('profile', fn (Builder $query) => $query->withCurrentIdentity()->where('minecraft_uuid', $uuid));
        $job = $query->first();
        if ($job !== null) {
            $status = $result['status'];
            $resetApplied = $job->action === 'reset' && $status === 'applied';
            $query->whereKey($job->id)->update([
                'status' => $status,
                'managed' => $job->managed && !$resetApplied,
                'last_auto_fingerprint' => $resetApplied ? null : $job->last_auto_fingerprint,
            ]);
        }
    }

    private function payload(Server $server, Synchronization $job, bool $enabled): ?array
    {
        $profile = $job->profile;
        $target = $this->target($server, $profile, $job, $enabled);
        if ($target === null || $target['fingerprint'] !== $job->target_fingerprint) {
            return null;
        }

        $header = ['uuid' => PlayerUuid::hyphenated($profile->minecraft_uuid), 'revision' => $job->revision];
        if ($job->action === 'reset') {
            return $header + ['reset' => true];
        }
        $disk = Storage::disk('local');
        $bytes = $disk->exists($profile->skin_path) && $disk->size($profile->skin_path) <= Profiles::MAX_IMAGE_BYTES
            ? $disk->get($profile->skin_path) : null;
        if ($bytes === null) {
            Synchronization::query()->whereKey($job->id)->where('revision', $job->revision)->pending()
                ->update(['status' => 'error', 'last_auto_fingerprint' => $job->target_fingerprint]);

            return null;
        }

        return $header + ['png' => base64_encode($bytes), 'variant' => $profile->variant];
    }

    private function recordPoll(Server $server): void
    {
        DB::transaction(function () use ($server): void {
            $current = $this->currentConnector($server)->lockForUpdate()->first(['id', 'status']);
            abort_if($current === null, 401);
            abort_unless(($current->status['connector_platform'] ?? 'bukkit') === 'bukkit', 403);

            ServerIntegration::query()->updateOrCreate(['server_id' => $server->id], [
                'connector_token_hash' => $server->token_hash,
                'polled_at' => now(),
            ]);
        });
    }

    private function currentConnector(Server $server): Builder
    {
        return Server::query()->linkedKraftLink('minecraft')->whereKey($server->id)
            ->where('token_hash', $server->token_hash);
    }

    private function reconcile(Server $server, array $players, bool $enabled): void
    {
        $profiles = Profile::query()->withCurrentIdentity()->whereIn('minecraft_uuid', $players)
            ->with(['synchronizations' => fn ($query) => $query->where('server_id', $server->id)])
            ->get();
        foreach ($profiles as $candidate) {
            $existing = $candidate->synchronizations->first();
            $target = $this->target($server, $candidate, $existing, $enabled);
            if (!$this->needsReconciliation($server, $existing, $target)) {
                continue;
            }

            DB::transaction(function () use ($server, $candidate, $enabled): void {
                $profile = $this->lockCurrentProfile($candidate);
                if ($profile === null) {
                    return;
                }
                $job = $profile->synchronizations()->where('server_id', $server->id)->lockForUpdate()->first();
                $target = $this->target($server, $profile, $job, $enabled);
                if (!$this->needsReconciliation($server, $job, $target) || !$this->currentConnector($server)->exists()) {
                    return;
                }
                if ($target === null || ($job?->isPendingFor($server) && $job->last_auto_fingerprint === $target['fingerprint'])) {
                    $job->update(['status' => 'blocked']);

                    return;
                }

                $values = [
                    'connector_token_hash' => $server->token_hash,
                    'revision' => (string) Str::uuid(),
                    'action' => $target['action'],
                    'target_fingerprint' => $target['fingerprint'],
                    'managed' => $this->isManaged($server, $job),
                    'status' => 'pending',
                    'expires_at' => now()->addDay(),
                ];
                if ($job?->last_auto_fingerprint === $target['fingerprint']) {
                    $values['last_auto_fingerprint'] = null;
                }
                $profile->synchronizations()->updateOrCreate(['server_id' => $server->id], $values);
            });
        }
    }

    private function target(Server $server, Profile $profile, ?Synchronization $job, bool $enabled): ?array
    {
        $action = 'reset';
        $material = '';
        if ($this->profiles->textureEnabled('skin') && $profile->auto_sync && $profile->skin_path !== null && $this->canUpload($profile)) {
            $action = 'upload';
            $material = $profile->skin_path."\0".$profile->variant;
        }
        if ($action === 'reset' ? !$this->isManaged($server, $job) : !$enabled) {
            return null;
        }

        return ['action' => $action, 'fingerprint' => hash('sha256', $server->token_hash."\0".$action."\0".$material)];
    }

    private function needsReconciliation(Server $server, ?Synchronization $job, ?array $target): bool
    {
        if ($job?->isPendingFor($server)) {
            if ($target === null) {
                return $job->last_auto_fingerprint !== $job->target_fingerprint;
            }

            return $job->target_fingerprint !== $target['fingerprint'];
        }

        if ($target === null) {
            return false;
        }
        if ($job?->last_auto_fingerprint !== $target['fingerprint']) {
            return true;
        }

        if ($job->target_fingerprint !== $target['fingerprint'] || !in_array($job->status, ['error', 'pending'], true)) {
            return false;
        }

        $failedAt = $job->status === 'pending' ? $job->expires_at : $job->updated_at;

        return $failedAt->lte(now()->subSeconds(self::RETRY_SECONDS));
    }

    private function markDispatched(Server $server, Synchronization $candidate): bool
    {
        return DB::transaction(function () use ($server, $candidate): bool {
            $profile = $this->lockCurrentProfile($candidate->profile);
            if ($profile === null || !$this->profiles->gameSyncEnabled()) {
                return false;
            }
            $job = Synchronization::query()->whereKey($candidate->id)->forConnector($server)->onCurrentConnector()
                ->where('revision', $candidate->revision)->pending()->lockForUpdate()->first();
            if ($job === null) {
                return false;
            }
            $target = $this->target($server, $profile, $job, $this->serverSyncEnabled($server));
            if ($target === null || $target['fingerprint'] !== $job->target_fingerprint) {
                return false;
            }
            if ($job->last_auto_fingerprint !== $job->target_fingerprint) {
                $job->update([
                    'last_auto_fingerprint' => $job->target_fingerprint,
                    'managed' => $job->managed || $job->action === 'upload',
                ]);
            }

            return true;
        });
    }

    private function lockCurrentProfile(Profile $candidate): ?Profile
    {
        return Profile::query()->whereKey($candidate->id)->withCurrentIdentity()
            ->where('minecraft_uuid', $candidate->minecraft_uuid)->lockForUpdate()->first();
    }

    private function isManaged(Server $server, ?Synchronization $job): bool
    {
        return $job?->managed === true && $job->connector_token_hash === $server->token_hash;
    }

    private function canUpload(Profile $profile): bool
    {
        $user = User::query()->find($profile->user_id);

        return $user !== null && $user->canAuthenticateAsMember() &&
            $user->hasPermission('skins.use') && $user->hasPermission('skins.upload');
    }
}
