<?php

namespace KraftInk\Plugins\Skins\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Skins\Services\Profiles;
use KraftInk\Plugins\Skins\Services\SkinRenderer;
use KraftInk\Plugins\Skins\Services\TextureFiles;

class TextureApiController
{
    public function __construct(private Profiles $profiles, private TextureFiles $files)
    {
    }

    public function profile(string $player): JsonResponse
    {
        $this->profiles->ensureMinecraft();
        $profile = $this->files->publicProfile($player);
        $textures = $this->files->describe(
            $profile,
            fn ($kind) => route('plugins.skins.api.texture', ['player' => $player, 'kind' => $kind]),
            public: true,
        );
        abort_if($textures['skin'] === null && $textures['cape'] === null, 404);

        return $this->json(['player' => $player] + $textures);
    }

    public function texture(string $player, string $kind): Response
    {
        $this->profiles->ensureMinecraft();

        return $this->png($this->files->file($this->files->publicProfile($player), $kind, public: true));
    }

    public function render(string $player, string $view, SkinRenderer $renderer): Response
    {
        $this->profiles->ensureMinecraft();
        $file = $this->files->file($this->files->publicProfile($player), 'skin', public: true);
        abort_if($file === null, 404);
        $bytes = $this->files->contents($file['path']);
        abort_if($bytes === null, 404);

        return $this->imageResponse($renderer->render($bytes, $file['variant'], $view));
    }

    public function defaultImage(): Response
    {
        $this->profiles->ensureMinecraft();

        return $this->png($this->files->fallback());
    }

    public function own(Request $request): JsonResponse
    {
        $this->profiles->ensureMinecraft();
        $uuid = $this->profiles->identity($request->user());
        abort_if($uuid === null, 404);

        return $this->json(['minecraft_uuid' => $uuid] + $this->files->describe(
            $this->files->ownProfile($request->user(), $uuid),
            fn ($kind) => route('plugins.skins.api.own-texture', ['kind' => $kind]),
            public: false,
        ));
    }

    public function ownTexture(Request $request, string $kind): Response
    {
        $this->profiles->ensureMinecraft();
        $uuid = $this->profiles->identity($request->user());
        abort_if($uuid === null, 404);

        return $this->png($this->files->file($this->files->ownProfile($request->user(), $uuid), $kind, public: false));
    }

    public function upload(Request $request, string $kind): Response
    {
        $this->profiles->ensureMinecraft();
        abort_unless($this->profiles->textureEnabled($kind), 404);
        $data = $request->validate([
            $kind => ['required', 'file', 'max:32'],
            'variant' => $kind === 'skin' ? ['sometimes', Rule::in(['classic', 'slim'])] : ['prohibited'],
        ]);
        $this->profiles->update($request->user(), $data);

        return response()->noContent();
    }

    private function png(?array $file): Response
    {
        $bytes = $file === null ? null : $this->files->contents($file['path']);
        abort_if($bytes === null, 404);

        return $this->imageResponse($bytes);
    }

    private function imageResponse(string $png): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
