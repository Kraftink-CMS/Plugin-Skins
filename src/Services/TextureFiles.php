<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Events\ApiProfileBuilding;
use App\Models\Accounts\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use KraftInk\Plugins\Skins\Models\Profile;
use League\Flysystem\UnableToRetrieveMetadata;

class TextureFiles
{
    public function __construct(private Profiles $profiles, private DefaultSkin $defaults)
    {
    }

    public function publicProfile(string $player): ?Profile
    {
        if (str_starts_with($player, 'id-')) {
            $id = filter_var(substr($player, 3), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false, 404);
        } else {
            $uuid = Profiles::javaUuid($player);
            abort_unless($uuid !== null || preg_match('/^[a-zA-Z0-9_]{3,16}$/D', $player), 404);
        }
        if (!$this->profiles->publicSharingEnabled()) {
            return null;
        }

        $columns = [];
        foreach (['skin', 'cape'] as $kind) {
            if ($this->profiles->textureEnabled($kind)) {
                $columns[] = $kind.'_public';
            }
        }
        if ($columns === []) {
            return null;
        }

        $query = Profile::query()->whereAny($columns, '=', true);
        if (isset($id)) {
            return $query->withCurrentIdentity()->where('user_id', $id)->first();
        }
        if ($uuid !== null) {
            return $query->withCurrentIdentity()->where('minecraft_uuid', $uuid)->first();
        }

        return $query->whereExists(Profile::verifiedIdentity()
            ->whereRaw('LOWER(game_identities.username) = ?', [strtolower($player)]))->first();
    }

    public function ownProfile(User $user, string $uuid): ?Profile
    {
        return Profile::query()->where('user_id', $user->id)->where('minecraft_uuid', $uuid)->withCurrentIdentity()->first();
    }

    public function contributeToApiProfile(ApiProfileBuilding $event): void
    {
        if (!in_array('skins:read', $event->scopes, true) || !$event->user->hasPermission('skins.use')) {
            return;
        }
        $uuid = $this->profiles->identity($event->user);
        if ($uuid !== null) {
            $event->extensions['skins'] = ['minecraft_uuid' => $uuid] + $this->describe(
                $this->ownProfile($event->user, $uuid),
                fn ($kind) => route('plugins.skins.api.own-texture', ['kind' => $kind]),
                public: false,
            );
        }
    }

    public function fallback(): ?array
    {
        return $this->defaults->enabled() ? $this->defaults->texture() : null;
    }

    public function file(?Profile $profile, string $kind, bool $public): ?array
    {
        $path = $this->profiles->textureEnabled($kind) ? $profile?->{$kind.'_path'} : null;
        if ($path && (!$public || ($this->profiles->publicSharingEnabled() && $profile->{$kind.'_public'})) && Storage::disk('local')->exists($path)) {
            return ['path' => $path, 'variant' => $profile->variant, 'default' => false];
        }

        $default = $kind === 'skin' ? $this->fallback() : null;

        return $default === null ? null : ['path' => $default['path'], 'variant' => $default['variant'], 'default' => true];
    }

    public function contents(string $path): ?string
    {
        $disk = Storage::disk('local');
        try {
            return $disk->exists($path) && $disk->size($path) <= Profiles::MAX_IMAGE_BYTES ? $disk->get($path) : null;
        } catch (UnableToRetrieveMetadata) {
            return null;
        }
    }

    public function describe(?Profile $profile, callable $url, bool $public): array
    {
        $details = [];
        $disk = Storage::disk('local');
        foreach (['skin', 'cape'] as $kind) {
            $file = $this->file($profile, $kind, $public);
            $bytes = $file === null ? null : $this->contents($file['path']);
            try {
                $modified = $bytes === null ? null : $disk->lastModified($file['path']);
            } catch (UnableToRetrieveMetadata) {
                $modified = null;
            }
            $details[$kind] = $bytes === null ? null : [
                'url' => $url($kind),
                'hash' => 'sha256:'.hash('sha256', $bytes),
                'last_modified' => $modified === null ? null : Carbon::createFromTimestampUTC($modified)->toIso8601String(),
            ];
            if ($kind === 'skin' && $details[$kind] !== null) {
                $details[$kind] += [
                    'variant' => $file['variant'],
                    'slim' => $file['variant'] === 'slim',
                    'default' => $file['default'],
                ];
            }
        }

        return $details;
    }
}
