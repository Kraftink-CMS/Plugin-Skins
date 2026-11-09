<?php

namespace KraftInk\Plugins\Skins\Services;

use App\Kraft\Auth\Avatars\AvatarIdentity;
use App\Kraft\Auth\Avatars\AvatarProvider;

class SkinAvatarProvider implements AvatarProvider
{
    public function __construct(private TextureFiles $files)
    {
    }

    public function id(): string
    {
        return 'skins';
    }

    public function metadata(): array
    {
        return ['name' => __('skins::api.avatar_name'), 'description' => __('skins::api.avatar_description')];
    }

    public function url(AvatarIdentity $identity): ?string
    {
        if ($identity->game !== 'minecraft' || !function_exists('imagecreatefromstring') || $identity->uuid === null) {
            return null;
        }
        $uuid = Profiles::javaUuid($identity->uuid);
        if ($uuid === null || $this->files->file($this->files->publicProfile($uuid), 'skin', public: true) === null) {
            return null;
        }

        return route('plugins.skins.api.render', ['player' => $uuid, 'view' => 'face']);
    }
}
