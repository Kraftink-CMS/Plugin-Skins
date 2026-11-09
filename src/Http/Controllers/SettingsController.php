<?php

namespace KraftInk\Plugins\Skins\Http\Controllers;

use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\System\TableQuery;
use App\Models\Servers\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KraftInk\Plugins\Skins\Services\DefaultSkin;
use KraftInk\Plugins\Skins\Services\Profiles;
use KraftInk\Plugins\Skins\Services\ServerSkins;

class SettingsController
{
    public function index(Request $request, Profiles $profiles, ServerSkins $skins, DefaultSkin $defaults): View
    {
        $profiles->ensureMinecraft();
        $canManageServers = $request->user()->hasPermission('servers.manage');
        $servers = TableQuery::paginate(
            Server::query()->where('game', 'minecraft')
                ->select(['id', 'name', 'game', 'enabled', 'connection', 'token_hash', 'status', 'last_seen_at'])
                ->orderBy('name')->orderBy('id'),
            ['name'],
            search: ['name'],
        )->through(fn (Server $server): array => [
            'id' => $server->id,
            'name' => $server->name,
            'state' => $skins->integrationState($server),
            'sync' => $skins->serverSyncEnabled($server),
            'url' => $canManageServers ? route(
                $server->connection === 'kraftlink' ? 'admin.servers.link.show' : 'admin.servers.index',
                $server->connection === 'kraftlink' ? $server : [],
            ) : null,
        ]);

        return view('skins::admin.settings', [
            'customSkins' => $profiles->textureEnabled('skin'),
            'capes' => $profiles->textureEnabled('cape'),
            'publishNewUploads' => $profiles->publicSharingEnabled(),
            'gameSync' => $profiles->gameSyncEnabled(),
            'defaultSkin' => $defaults->metadata(),
            'fallback' => $defaults->enabled(),
            'gdAvailable' => function_exists('imagecreatefromstring'),
            'servers' => $servers,
            'serverSearch' => TableQuery::options($servers)['search'] !== '',
            'canManageServers' => $canManageServers,
        ]);
    }

    public function update(Request $request, Profiles $profiles, Settings $settings, DefaultSkin $defaults, ServerSkins $skins): RedirectResponse
    {
        $profiles->ensureMinecraft();
        $section = $request->validate(['section' => ['sometimes', 'string', Rule::in(['site', 'game', 'fallback', 'server'])]])['section'] ?? 'site';
        if ($section === 'fallback') {
            $defaults->update($request->validate([
                'fallback' => ['required', 'boolean'],
                'default_skin' => ['nullable', 'file', 'mimetypes:image/png', 'max:32', Rule::prohibitedIf($request->boolean('remove_default_skin'))],
                'default_variant' => ['sometimes', Rule::in(['classic', 'slim'])],
                'remove_default_skin' => ['sometimes', 'boolean'],
            ]));
        } elseif ($section === 'server') {
            $this->updateServer($request, $skins);
        } else {
            $this->updateOptions($request, $settings, $section);
        }

        return back()->with('status', __('skins::messages.admin.saved'));
    }

    private function updateServer(Request $request, ServerSkins $skins): void
    {
        $data = $request->validate([
            'server_id' => ['required', 'integer', 'min:1'],
            'auto_sync' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $skins): void {
            $server = Server::query()->where('game', 'minecraft')->lockForUpdate()->findOrFail($data['server_id']);
            $enabled = (bool) $data['auto_sync'];
            $skins->setServerSyncEnabled($server, $enabled);
            Audit::record('skins.settings_changed', ['server' => $server->id, 'auto_sync' => $enabled]);
        });
    }

    private function updateOptions(Request $request, Settings $settings, string $section): void
    {
        $rules = $section === 'game' ? ['game_sync' => ['required', 'boolean']] : [
            'skins' => ['required', 'boolean'],
            'capes' => ['required', 'boolean'],
            'public_sharing' => ['required', 'boolean'],
        ];
        $options = array_map(fn ($value) => (bool) $value, $request->validate($rules));

        DB::transaction(function () use ($options, $settings): void {
            foreach ($options as $option => $enabled) {
                $settings->set($option === 'public_sharing' ? 'skins.publish_new_uploads' : 'skins.'.$option.'_enabled', $enabled);
            }
            Audit::record('skins.settings_changed', $options);
        });
    }
}
