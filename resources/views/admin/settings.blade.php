@extends('admin.layouts.layout')

@section('title', __('skins::messages.title'))
@section('description', __('skins::admin.description'))

@section('actions')
    @if ($canManageServers)
        <a class="btn btn-outline-secondary" href="{{ route('admin.servers.index') }}">{{ __('skins::admin.manage_servers') }}</a>
    @endif
@endsection

@section('content')
    <div class="vstack gap-4">
        <form class="card mb-0" method="post" action="{{ route('plugins.skins.admin.update') }}">
            @csrf
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h2 class="card-title">{{ __('skins::messages.site_textures') }}</h2>
                <span class="badge text-bg-secondary">Minecraft</span>
            </div>
            <div class="card-body">
                <p class="text-body-secondary">{{ __('skins::admin.site_intro') }}</p>
                @foreach ([
                    'skins' => [$customSkins, 'skins::admin.custom_skins', 'skins::admin.custom_skins_help'],
                    'capes' => [$capes, 'skins::admin.custom_capes', 'skins::admin.capes_help'],
                    'public_sharing' => [$publishNewUploads, 'skins::admin.publish_new_uploads', 'skins::admin.publish_new_uploads_help'],
                ] as $field => [$enabled, $label, $help])
                    <div @class(['form-check', 'form-switch', 'mb-3' => !$loop->last])>
                        <input type="hidden" name="{{ $field }}" value="0">
                        <input class="form-check-input @error($field) is-invalid @enderror" type="checkbox" role="switch"
                            name="{{ $field }}" value="1" id="skins-admin-{{ $field }}"
                            aria-describedby="skins-admin-{{ $field }}-help @error($field) skins-admin-{{ $field }}-error @enderror"
                            @checked(old($field, $enabled))>
                        <label class="form-check-label" for="skins-admin-{{ $field }}">{{ __($label) }}</label>
                        <div class="form-text" id="skins-admin-{{ $field }}-help">{{ __($help) }}</div>
                        @error($field)
                            <div class="invalid-feedback" id="skins-admin-{{ $field }}-error">{{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </form>

        <form class="card mb-0" method="post" enctype="multipart/form-data" action="{{ route('plugins.skins.admin.update') }}">
            @csrf
            <input type="hidden" name="section" value="fallback">
            <div class="card-header">
                <h2 class="card-title">{{ __('skins::admin.fallback_title') }}</h2>
            </div>
            <div class="card-body">
                <p class="text-body-secondary">{{ __('skins::admin.fallback_intro') }}</p>
                @unless ($gdAvailable)
                    <div class="alert alert-warning" role="status">{{ __('skins::admin.gd_missing') }}</div>
                @endunless
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="fallback" value="0">
                    <input class="form-check-input @error('fallback') is-invalid @enderror" type="checkbox" role="switch"
                        name="fallback" value="1" id="skins-admin-fallback"
                        aria-describedby="skins-admin-fallback-help @error('fallback') skins-admin-fallback-error @enderror"
                        @checked(old('fallback', $fallback))>
                    <label class="form-check-label" for="skins-admin-fallback">{{ __('skins::admin.fallback') }}</label>
                    <div class="form-text" id="skins-admin-fallback-help">{{ __('skins::admin.fallback_help') }}</div>
                    @error('fallback')
                        <div class="invalid-feedback" id="skins-admin-fallback-error">{{ $message }}</div>
                    @enderror
                </div>
                @if ($defaultSkin !== null)
                    <figure class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <img class="img-thumbnail" width="96" src="{{ $defaultSkin['url'] }}"
                            alt="{{ __('skins::admin.default_preview') }}">
                        <figcaption class="small text-body-secondary">{{ __('skins::admin.default_stored') }}</figcaption>
                    </figure>
                @endif
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="skins-admin-default-file">{{ __('skins::admin.default_file') }}</label>
                        <input class="form-control @error('default_skin') is-invalid @enderror" type="file" accept="image/png"
                            name="default_skin" id="skins-admin-default-file"
                            aria-describedby="skins-admin-default-file-help @error('default_skin') skins-admin-default-file-error @enderror">
                        <div class="form-text" id="skins-admin-default-file-help">{{ __('skins::messages.invalid_skin') }}</div>
                        @error('default_skin')
                            <div class="invalid-feedback" id="skins-admin-default-file-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="skins-admin-default-variant">{{ __('skins::messages.variant') }}</label>
                        <select class="form-select @error('default_variant') is-invalid @enderror" name="default_variant"
                            id="skins-admin-default-variant" @error('default_variant') aria-describedby="skins-admin-default-variant-error" @enderror>
                            @foreach (['classic', 'slim'] as $variant)
                                <option value="{{ $variant }}" @selected(old('default_variant', $defaultSkin['variant'] ?? 'classic') === $variant)>
                                    {{ __('skins::messages.'.$variant) }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_variant')
                            <div class="invalid-feedback" id="skins-admin-default-variant-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                @if ($defaultSkin !== null)
                    <div class="form-check mt-3">
                        <input class="form-check-input @error('remove_default_skin') is-invalid @enderror" type="checkbox"
                            name="remove_default_skin" value="1" id="skins-admin-remove-default"
                            aria-describedby="skins-admin-remove-default-help @error('remove_default_skin') skins-admin-remove-default-error @enderror"
                            @checked(old('remove_default_skin', false))>
                        <label class="form-check-label" for="skins-admin-remove-default">{{ __('skins::admin.remove_default') }}</label>
                        <div class="form-text" id="skins-admin-remove-default-help">{{ __('skins::admin.remove_default_help') }}</div>
                        @error('remove_default_skin')
                            <div class="invalid-feedback" id="skins-admin-remove-default-error">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </form>

        <form class="card mb-0" method="post" action="{{ route('plugins.skins.admin.update') }}">
            @csrf
            <input type="hidden" name="section" value="game">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h2 class="card-title">{{ __('skins::admin.game_title') }}</h2>
                <span class="badge text-bg-secondary">Minecraft Java</span>
            </div>
            <div class="card-body">
                <p class="text-body-secondary">{{ __('skins::admin.game_intro') }}</p>
                @unless ($customSkins)
                    <p class="alert alert-secondary" role="status">{{ __('skins::admin.custom_skins_disabled') }}</p>
                @endunless
                <div class="form-check form-switch">
                    <input type="hidden" name="game_sync" value="0">
                    <input class="form-check-input @error('game_sync') is-invalid @enderror" type="checkbox" role="switch"
                        name="game_sync" value="1" id="skins-admin-game-sync"
                        aria-describedby="skins-admin-game-sync-help @error('game_sync') skins-admin-game-sync-error @enderror"
                        @checked(old('game_sync', $gameSync))>
                    <label class="form-check-label" for="skins-admin-game-sync">{{ __('skins::admin.game_sync') }}</label>
                    <div class="form-text" id="skins-admin-game-sync-help">{{ __('skins::admin.game_sync_help') }}</div>
                    @error('game_sync')
                        <div class="invalid-feedback" id="skins-admin-game-sync-error">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </form>

        <section class="card mb-0" id="skins-admin-servers" aria-labelledby="skins-admin-servers-title" data-table data-ajax-pagination tabindex="-1">
            <div class="card-header">
                <h2 class="card-title" id="skins-admin-servers-title">{{ __('skins::admin.servers_title') }}</h2>
            </div>
            <div class="card-body">
                @unless ($gameSync)
                    <p class="alert alert-secondary mb-3" role="status">{{ __('skins::admin.game_sync_disabled') }}</p>
                @endunless
                <h3 class="h6">{{ __('skins::admin.setup_title') }}</h3>
                <ol class="mb-3">
                    <li>{{ __('skins::admin.setup_link') }}</li>
                    <li>{{ __('skins::admin.setup_plugins') }}</li>
                    <li>{{ __('skins::admin.setup_player') }}</li>
                </ol>
                <p class="text-body-secondary">{{ __('skins::admin.server_sync_help') }}</p>
                <x-tables.controls :paginator="$servers" />
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('skins::messages.server') }}</th>
                            <th scope="col">{{ __('common.labels.status') }}</th>
                            <th scope="col">{{ __('skins::admin.server_sync') }}</th>
                            <th scope="col">{{ __('skins::admin.next_step') }}</th>
                            @if ($canManageServers)
                                <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($servers as $server)
                            <tr>
                                <th scope="row">{{ $server['name'] }}</th>
                                <td><span @class([
                                    'badge text-wrap',
                                    'text-bg-success' => $server['state'] === 'ready',
                                    'text-bg-secondary' => in_array($server['state'], ['inactive', 'wrong_transport', 'unsupported_platform'], true),
                                    'text-bg-warning' => in_array($server['state'], ['unlinked', 'awaiting_platform', 'stale', 'bukkit_reported'], true),
                                ])>{{ __('skins::admin.states.'.$server['state'].'.label') }}</span></td>
                                <td>
                                    @if (in_array($server['state'], ['inactive', 'wrong_transport', 'unsupported_platform', 'unlinked'], true))
                                        <span class="badge text-bg-secondary">{{ __('skins::admin.server_sync_unavailable') }}</span>
                                    @else
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span @class(['badge text-nowrap', 'text-bg-primary' => $server['sync'], 'text-bg-secondary' => !$server['sync']])>
                                                {{ __($server['sync'] ? 'skins::admin.server_sync_on' : 'skins::admin.server_sync_off') }}
                                            </span>
                                            <form method="post" action="{{ route('plugins.skins.admin.update') }}">
                                                @csrf
                                                <input type="hidden" name="section" value="server">
                                                <input type="hidden" name="server_id" value="{{ $server['id'] }}">
                                                <input type="hidden" name="auto_sync" value="{{ $server['sync'] ? 0 : 1 }}">
                                                <button class="btn btn-outline-secondary btn-sm" type="submit"
                                                    aria-label="{{ __($server['sync'] ? 'skins::admin.server_sync_disable' : 'skins::admin.server_sync_enable', ['server' => $server['name']]) }}">
                                                    {{ __($server['sync'] ? 'common.actions.disable' : 'common.actions.enable') }}
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                                <td class="small">{{ __('skins::admin.states.'.$server['state'].'.next') }}</td>
                                @if ($server['url'] !== null)
                                    <td class="text-end">
                                        <a class="btn btn-outline-secondary btn-sm text-nowrap" href="{{ $server['url'] }}">{{ __('common.actions.open') }}</a>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <x-tables.empty :colspan="$canManageServers ? 5 : 4"
                                :message="__($serverSearch ? 'skins::admin.no_matching_servers' : 'skins::admin.no_servers')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $servers->links() }}</div>
        </section>
    </div>
@endsection
