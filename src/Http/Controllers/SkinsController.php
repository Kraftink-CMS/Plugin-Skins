<?php

namespace KraftInk\Plugins\Skins\Http\Controllers;

use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KraftInk\Plugins\Skins\Models\Profile;
use KraftInk\Plugins\Skins\Services\DefaultSkin;
use KraftInk\Plugins\Skins\Services\Profiles;
use KraftInk\Plugins\Skins\Services\TextureFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkinsController
{
    public function __construct(private Profiles $profiles)
    {
    }

    public function panel(User $user, TextureFiles $files): View|string
    {
        $this->profiles->ensureMinecraft();
        $enabledTextures = [
            'skin' => $this->profiles->textureEnabled('skin'),
            'cape' => $this->profiles->textureEnabled('cape'),
        ];
        $uuid = $this->profiles->identity($user);
        $profile = $uuid === null ? null : $files->ownProfile($user, $uuid);
        if (!$enabledTextures['skin'] && !$enabledTextures['cape'] && !$profile?->skin_path && !$profile?->cape_path) {
            return '';
        }

        $publicSharing = $this->profiles->publicSharingEnabled();
        $publicTextures = [];
        if ($publicSharing && $profile !== null) {
            foreach (array_keys(array_filter($enabledTextures)) as $kind) {
                $file = $files->file($profile, $kind, public: true);
                if ($file !== null && !$file['default']) {
                    $publicTextures[$kind] = route('plugins.skins.api.texture', ['player' => $uuid, 'kind' => $kind]);
                }
            }
        }

        $default = $enabledTextures['skin'] ? $files->fallback() : null;
        $defaultSkin = $default === null ? null : ['variant' => $default['variant'], 'url' => route('plugins.skins.api.default')];

        return view('skins::index', compact('uuid', 'profile', 'defaultSkin', 'enabledTextures', 'publicSharing', 'publicTextures') + [
            'canUpload' => $user->hasPermission('skins.upload'),
            'gameSync' => $enabledTextures['skin'] && $this->profiles->gameSyncEnabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->profiles->ensureMinecraft();
        $profileUrl = route('account.index').'#skins';
        $skinsEnabled = $this->profiles->textureEnabled('skin');
        try {
            $input = $request->validate([
                'skin' => $skinsEnabled ? ['nullable', 'file', 'max:32'] : ['prohibited'],
                'cape' => $this->profiles->textureEnabled('cape') ? ['nullable', 'file', 'max:32'] : ['prohibited'],
                'variant' => $skinsEnabled ? ['sometimes', Rule::in(['classic', 'slim'])] : ['prohibited'],
                'remove_skin' => ['sometimes', 'boolean'],
                'remove_cape' => ['sometimes', 'boolean'],
            ]);
            $this->profiles->update($request->user(), $input);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('skins')->redirectTo($profileUrl);
        }

        return redirect()->to($profileUrl)->with('skins.status', __('skins::messages.saved'));
    }

    public function image(Request $request, string $uuid, string $kind): StreamedResponse
    {
        $this->profiles->ensureMinecraft();
        $uuid = Profiles::javaUuid($uuid);
        abort_if($uuid === null || !$this->profiles->textureEnabled($kind), 404);
        $profile = Profile::query()->where('minecraft_uuid', $uuid)->withCurrentIdentity()->firstOrFail();
        $user = $request->user();
        abort_unless(
            ($profile->{$kind.'_public'} && $this->profiles->publicSharingEnabled()) ||
            ($user !== null && $profile->user_id === $user->id && $user->hasPermission('skins.use')),
            404,
        );

        $path = $profile->{$kind.'_path'};
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, $kind.'.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function defaultPreview(DefaultSkin $defaults): StreamedResponse
    {
        $this->profiles->ensureMinecraft();
        $texture = $defaults->texture();
        abort_if($texture === null, 404);

        return Storage::disk('local')->response($texture['path'], 'skin.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
