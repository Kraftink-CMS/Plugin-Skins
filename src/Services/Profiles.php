<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Events\PruningData;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\PlayerUuid;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Kraft\Settings;
use App\Kraft\System\RetentionPolicy;
use App\Models\Accounts\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Skins\Models\Profile;
use KraftInk\Plugins\Skins\Models\Synchronization;
use Throwable;

class Profiles
{
    public const int MAX_IMAGE_BYTES = 32_768;

    public const string DIRECTORY = 'skins/textures';

    public function __construct(
        private GameRegistry $games,
        private Images $images,
        private FileDeletionQueue $deletions,
        private Settings $settings,
    ) {
    }

    public function ensureMinecraft(): void
    {
        abort_unless($this->games->activeId() === 'minecraft', 404);
    }

    public function textureEnabled(string $kind): bool
    {
        return match ($kind) {
            'skin' => (bool) $this->settings->get('skins.skins_enabled', true),
            'cape' => (bool) $this->settings->get('skins.capes_enabled', true),
            default => false,
        };
    }

    public function publicSharingEnabled(): bool
    {
        return (bool) $this->settings->get('skins.publish_new_uploads', false);
    }

    public function gameSyncEnabled(): bool
    {
        return (bool) $this->settings->get('skins.game_sync_enabled', true);
    }

    public static function javaUuid(string $value): ?string
    {
        $uuid = PlayerUuid::compact($value);

        return $uuid !== null && preg_match('/^[a-f0-9]{12}4[a-f0-9]{3}[89ab][a-f0-9]{15}$/D', $uuid) === 1 ? $uuid : null;
    }

    public function identity(User $user): ?string
    {
        $identity = $this->games->identityFor($user, 'minecraft');

        return $identity?->verified_at ? self::javaUuid($identity->uuid) : null;
    }

    public function update(User $user, array $input): void
    {
        $created = [];
        try {
            DB::transaction(function () use ($user, $input, &$created): void {
                $profile = $this->lockProfile($user);
                abort_if(isset($input['variant']) && !$this->textureEnabled('skin'), 404);
                $variant = $input['variant'] ?? $profile->variant;
                $canUpload = $user->hasPermission('skins.upload');
                abort_if(
                    !$canUpload && (isset($input['skin']) || isset($input['cape']) || $variant !== $profile->variant),
                    403,
                );
                $changes = ['variant' => $variant];
                if (isset($input['skin']) || ($profile->skin_path !== null && !($input['remove_skin'] ?? false) && $variant !== $profile->variant)) {
                    $changes['auto_sync'] = true;
                }
                $publishUploads = $this->publicSharingEnabled();
                foreach (['skin', 'cape'] as $kind) {
                    if (isset($input[$kind])) {
                        abort_unless($this->textureEnabled($kind), 404);
                        $path = $this->storeImage($profile, $kind, $input[$kind]);
                        $created[] = $path;
                        $changes[$kind.'_path'] = $path;
                        $changes[$kind.'_public'] = $publishUploads;
                    } elseif ($input['remove_'.$kind] ?? false) {
                        $changes[$kind.'_path'] = null;
                        $changes[$kind.'_public'] = false;
                    }
                }

                $previous = $profile->only(['skin_path', 'cape_path']);
                $profile->update($changes);
                foreach ($previous as $column => $path) {
                    if ($path !== null && $path !== $profile->{$column}) {
                        $this->deleteImage($path);
                    }
                }
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($created);
            throw $error;
        }
    }

    public function lockProfile(User $user): Profile
    {
        $user = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_unless($user->canAuthenticateAsMember(), 403);
        $uuid = $this->identity($user);
        if ($uuid === null) {
            throw ValidationException::withMessages(['profile' => __('skins::messages.verify')]);
        }

        $profile = Profile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($profile !== null && $profile->minecraft_uuid !== $uuid) {
            $this->delete($profile);
            $profile = null;
        }

        return $profile ?? Profile::query()->create(['user_id' => $user->id, 'minecraft_uuid' => $uuid]);
    }

    public function validateImage(UploadedFile $file, string $kind, string $field): void
    {
        $image = $this->images->validateUpload($file, maxBytes: self::MAX_IMAGE_BYTES, field: $field);
        $sizes = $kind === 'skin' ? [[64, 64], [64, 32]] : [[64, 32], [22, 17]];
        if (
            $image->mime !== 'image/png' || !in_array([$image->width, $image->height], $sizes, true) ||
            (function_exists('imagecreatefromstring') && !$this->images->canDecode($image))
        ) {
            throw ValidationException::withMessages([$field => __('skins::messages.invalid_'.$kind)]);
        }

        $this->images->prepareUpload($file, $image, $field);
    }

    public function delete(Profile $profile): void
    {
        $paths = array_filter($profile->only(['skin_path', 'cape_path']));
        $profile->delete();
        foreach ($paths as $path) {
            $this->deleteImage($path);
        }
    }

    public function prune(PruningData $event): void
    {
        $cutoff = now()->subDays(app(RetentionPolicy::class)->days('temporary'));
        $synchronizations = Synchronization::query()->where('expires_at', '<', $cutoff)->whereNull('last_auto_fingerprint');
        $event->add('skins.synchronizations', $event->dryRun ? $synchronizations->count() : $synchronizations->delete());

        $profiles = Profile::query()->whereNotExists(Profile::verifiedIdentity());
        if ($event->dryRun) {
            $event->add('skins.unlinked_profiles', $profiles->count());

            return;
        }

        $count = 0;
        foreach ($profiles->lazyById(100) as $candidate) {
            $count += (int) DB::transaction(function () use ($candidate): bool {
                $profile = Profile::query()->whereKey($candidate->id)
                    ->whereNotExists(Profile::verifiedIdentity())->lockForUpdate()->first();
                if ($profile === null) {
                    return false;
                }

                $this->delete($profile);

                return true;
            });
        }
        $event->add('skins.unlinked_profiles', $count);
    }

    private function storeImage(Profile $profile, string $kind, UploadedFile $file): string
    {
        $this->validateImage($file, $kind, $kind);
        $directory = self::DIRECTORY.'/'.$profile->minecraft_uuid;
        $stored = $file->storeAs($directory, Str::uuid().'.png', 'local');
        if ($stored === false) {
            throw ValidationException::withMessages([$kind => __('skins::messages.storage_error')]);
        }

        return $stored;
    }

    private function deleteImage(string $path): void
    {
        $this->deletions->schedule($path, self::DIRECTORY, ['skins__profiles' => ['skin_path', 'cape_path']]);
    }
}
