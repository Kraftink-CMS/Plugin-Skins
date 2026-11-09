<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Kraft\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DefaultSkin
{
    public function __construct(private Settings $settings, private Profiles $profiles)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->configured()['enabled'];
    }

    public function texture(): ?array
    {
        $configuration = $this->configured();
        if (!$configuration['path'] || !Storage::disk('local')->exists($configuration['path'])) {
            return null;
        }

        return $configuration;
    }

    public function metadata(): ?array
    {
        $texture = $this->texture();

        return $texture === null ? null : [
            'variant' => $texture['variant'],
            'url' => route('plugins.skins.admin.default-image'),
        ];
    }

    public function update(array $data): void
    {
        $file = $data['default_skin'] ?? null;
        if ($file !== null) {
            $this->profiles->validateImage($file, 'skin', 'default_skin');
        }
        $disk = Storage::disk('local');

        try {
            Cache::lock('skins.default_texture', 30)->block(3, function () use ($data, $file, $disk): void {
                $this->settings->refresh();
                $current = $this->configured();
                $enabled = (bool) ($data['fallback'] ?? $current['enabled']);
                $path = ($data['remove_default_skin'] ?? false) ? null : $current['path'];
                if ($file === null && $enabled && (!$path || !$disk->exists($path))) {
                    throw ValidationException::withMessages(['default_skin' => __('skins::api.default_required')]);
                }
                $newPath = $file?->storeAs(Profiles::DIRECTORY.'/default', Str::uuid().'.png', 'local');
                if ($newPath === false) {
                    throw ValidationException::withMessages(['default_skin' => __('skins::messages.storage_error')]);
                }

                $path = $newPath ?? $path;
                try {
                    $this->settings->set('skins.default_texture', [
                        'enabled' => $enabled,
                        'path' => $path,
                        'variant' => $data['default_variant'] ?? $current['variant'],
                    ]);
                } catch (Throwable $error) {
                    if ($newPath !== null && $this->configured()['path'] !== $newPath) {
                        $disk->delete($newPath);
                    }
                    throw $error;
                }
                if ($current['path'] !== null && $current['path'] !== $path) {
                    $disk->delete($current['path']);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['default_skin' => __('skins::api.default_busy')]);
        }
    }

    private function configured(): array
    {
        $data = $this->settings->get('skins.default_texture', []);
        $path = $data['path'] ?? null;

        return [
            'enabled' => ($data['enabled'] ?? false) === true,
            'path' => is_string($path) && preg_match('~^skins/textures/default/[a-f0-9-]{36}\.png$~D', $path) ? $path : null,
            'variant' => ($data['variant'] ?? null) === 'slim' ? 'slim' : 'classic',
        ];
    }
}
