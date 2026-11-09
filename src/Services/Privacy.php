<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Extensions\Contracts\PrivacyHandler;
use Illuminate\Support\Facades\Storage;
use KraftInk\Plugins\Skins\Models\Profile;

class Privacy implements PrivacyHandler
{
    public function export(ExportingPersonalData $event): void
    {
        $profile = Profile::query()->where('user_id', $event->user->id)->first();
        if ($profile === null) {
            return;
        }

        $data = $profile->only(['minecraft_uuid', 'skin_public', 'cape_public', 'auto_sync', 'variant', 'created_at', 'updated_at']);
        $disk = Storage::disk('local');
        foreach (['skin', 'cape'] as $kind) {
            $path = $profile->{$kind.'_path'};
            $bytes = $path && $disk->exists($path) && $disk->size($path) <= Profiles::MAX_IMAGE_BYTES
                ? $disk->get($path)
                : null;
            $data[$kind.'_png_base64'] = $bytes === null ? null : base64_encode($bytes);
        }
        $data['synchronizations'] = $profile->synchronizations()
            ->get(['server_id', 'action', 'status', 'created_at', 'updated_at']);
        $event->data['skins'] = $data;
    }

    public function anonymize(AccountDeleting $event): void
    {
        $profile = Profile::query()->where('user_id', $event->user->id)->lockForUpdate()->first();
        if ($profile !== null) {
            app(Profiles::class)->delete($profile);
        }
    }
}
