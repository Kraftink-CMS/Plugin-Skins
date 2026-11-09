@php
    $hasEnabledTextures = in_array(true, $enabledTextures, true);
    $kinds = array_values(array_filter(['skin', 'cape'], fn (string $kind) => $enabledTextures[$kind] || $profile?->{$kind.'_path'}));
    $titleKey = $hasEnabledTextures && count($kinds) === 1 ? 'skins::messages.'.$kinds[0] : 'skins::messages.title';
    $skinErrors = $errors->getBag('skins');
@endphp

@if ($uuid !== null)
    @pushOnce('head', 'skins.profile.css')
        <link rel="stylesheet" href="{{ route('site.extensions.assets.show', ['id' => 'skins', 'asset' => 'profile.css']) }}">
    @endPushOnce
    @if ($hasEnabledTextures)
        @pushOnce('scripts', 'skins.profile.js')
            <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'skins', 'asset' => 'profile.js']) }}"></script>
        @endPushOnce
    @endif
@endif

<section class="card skins-profile mb-4" id="skins" aria-labelledby="skins-title" data-skins-profile>
    <header class="card-header d-flex flex-wrap align-items-center gap-2">
        <h2 class="h5 mb-0" id="skins-title">{{ __($titleKey) }}</h2>
        @if ($uuid !== null)
            <span class="badge text-bg-success">{{ __('site.games.verified') }}</span>
        @endif
    </header>

    @if (session()->has('skins.status'))
        <div class="alert alert-success mx-3 mt-3 mb-0" role="status">{{ session('skins.status') }}</div>
    @endif
    @if ($skinErrors->any())
        <div class="alert alert-danger mx-3 mt-3 mb-0" role="alert">
            <ul class="mb-0">
                @foreach ($skinErrors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($uuid === null)
        <div class="card-body">
            <p class="mb-0">{{ __('skins::messages.verify') }}</p>
        </div>
        <div class="card-footer">
            <a class="btn btn-primary" href="{{ route('account.games.index') }}">{{ __('skins::messages.game_profiles') }}</a>
        </div>
    @else
        @php
            $canReplaceAny = $canUpload && $hasEnabledTextures;
            $canEdit = $canReplaceAny || $profile?->skin_path || $profile?->cape_path;
            $showVisibility = $canReplaceAny || $publicTextures !== [];
            $showGamePolicy = $gameSync && $canUpload;
        @endphp
        @if ($canEdit)
            <form method="post" action="{{ route('plugins.skins.update') }}" enctype="multipart/form-data" aria-labelledby="skins-title">
                @csrf
        @endif
            <div class="card-body">
                @if ($hasEnabledTextures)
                    <p class="text-body-secondary">{{ __($canReplaceAny ? 'skins::profile.files_help' : 'skins::profile.upload_unavailable') }}</p>
                @endif

                @foreach ($kinds as $kind)
                    @php
                        $hasPreview = $enabledTextures[$kind];
                        $canReplace = $canUpload && $hasPreview;
                        $hasFile = (bool) $profile?->{$kind.'_path'};
                        $hasControls = $canReplace || $hasFile;
                    @endphp
                    <div @class(['row', 'g-3', 'mb-4', 'border-top pt-3' => !$loop->first])>
                        @if ($hasControls)
                            <div @class(['col-md-6' => $hasPreview, 'col-12' => !$hasPreview])>
                                @if ($canReplace)
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" for="skins-{{ $kind }}">{{ __('skins::messages.'.$kind) }}</label>
                                        <input class="form-control @error($kind, 'skins') is-invalid @enderror" type="file" id="skins-{{ $kind }}"
                                            name="{{ $kind }}" accept="image/png"
                                            aria-describedby="skins-{{ $kind }}-help{{ $skinErrors->has($kind) ? ' skins-'.$kind.'-error' : '' }}"
                                            @error($kind, 'skins') aria-invalid="true" @enderror>
                                        @error($kind, 'skins')
                                            <div class="invalid-feedback" id="skins-{{ $kind }}-error">{{ $message }}</div>
                                        @enderror
                                        <div class="form-text" id="skins-{{ $kind }}-help">
                                            {{ __('skins::messages.invalid_'.$kind) }}
                                            @if ($kind === 'cape')
                                                {{ __('skins::profile.cape_usage') }}
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if ($kind === 'skin' && $canReplace)
                                    <div class="mb-3">
                                        <label class="form-label" for="skins-variant">{{ __('skins::messages.variant') }}</label>
                                        <select class="form-select @error('variant', 'skins') is-invalid @enderror" id="skins-variant" name="variant"
                                            aria-describedby="skins-variant-help{{ $skinErrors->has('variant') ? ' skins-variant-error' : '' }}"
                                            @error('variant', 'skins') aria-invalid="true" @enderror>
                                            @foreach (['classic', 'slim'] as $variant)
                                                <option value="{{ $variant }}" @selected(old('variant', $profile?->variant ?? 'classic') === $variant)>
                                                    {{ __('skins::messages.'.$variant) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('variant', 'skins')
                                            <div class="invalid-feedback" id="skins-variant-error">{{ $message }}</div>
                                        @enderror
                                        <div class="form-text" id="skins-variant-help">{{ __('skins::profile.variant_help') }}</div>
                                    </div>
                                @elseif ($kind === 'skin' && $hasFile && $hasPreview)
                                    <p class="mb-2"><strong>{{ __('skins::messages.variant') }}</strong> · {{ __('skins::messages.'.$profile->variant) }}</p>
                                @endif

                                @if ($hasFile)
                                    @if (!$hasPreview)
                                        <p class="small text-body-secondary mb-2">{{ __('skins::profile.disabled_'.$kind) }}</p>
                                    @elseif (isset($publicTextures[$kind]))
                                        <p class="small mb-2">
                                            <a href="{{ $publicTextures[$kind] }}" target="_blank" rel="noopener noreferrer">
                                                {{ __('skins::profile.public_'.$kind.'_link') }}
                                            </a>
                                        </p>
                                    @endif
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_{{ $kind }}" value="1"
                                            id="skins-remove-{{ $kind }}" @checked(old('remove_'.$kind))>
                                        <label class="form-check-label" for="skins-remove-{{ $kind }}">{{ __('skins::messages.remove_'.$kind) }}</label>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($hasPreview)
                            @php
                                $savedImage = $hasFile ? route('plugins.skins.image', ['uuid' => $uuid, 'kind' => $kind]) : null;
                                $fallbackImage = $kind === 'skin' ? ($defaultSkin['url'] ?? null) : null;
                                $shownImage = $savedImage ?? $fallbackImage;
                            @endphp
                            <div @class(['col-md-6' => $hasControls, 'col-12' => !$hasControls])>
                                <section data-skins-preview="{{ $kind }}" data-saved="{{ $savedImage }}" data-default="{{ $fallbackImage }}"
                                    data-model="{{ $profile?->variant ?? 'classic' }}" data-default-model="{{ $defaultSkin['variant'] ?? 'classic' }}"
                                    data-saved-label="{{ __('skins::profile.saved_png') }}" data-default-label="{{ __('skins::profile.default_png') }}"
                                    data-draft-label="{{ __('skins::profile.unsaved_png') }}" data-empty-label="{{ __('skins::profile.no_'.$kind) }}"
                                    data-invalid-label="{{ __('skins::profile.preview_error') }}" aria-labelledby="skins-preview-{{ $kind }}">
                                    <h3 class="h6" id="skins-preview-{{ $kind }}">{{ __('skins::profile.'.$kind.'_preview') }}</h3>
                                    <div class="skins-preview-stage">
                                        @if ($shownImage)
                                            <img class="skins-preview-original" data-skins-original src="{{ $shownImage }}"
                                                alt="{{ __('skins::messages.'.$kind) }}">
                                        @endif
                                        @if ($kind === 'skin')
                                            <div class="skins-preview-views" data-skins-render hidden>
                                                <figure class="m-0 text-center">
                                                    <canvas data-skins-front width="24" height="32" aria-hidden="true"></canvas>
                                                    <figcaption class="small text-body-secondary">{{ __('skins::profile.front') }}</figcaption>
                                                </figure>
                                                <figure class="m-0 text-center">
                                                    <canvas data-skins-back width="24" height="32" aria-hidden="true"></canvas>
                                                    <figcaption class="small text-body-secondary">{{ __('skins::profile.back') }}</figcaption>
                                                </figure>
                                            </div>
                                        @else
                                            <canvas class="skins-preview-cape" data-skins-render width="10" height="16" aria-hidden="true" hidden></canvas>
                                        @endif
                                        <p class="small text-center text-body-secondary mb-0" data-skins-state role="status">
                                            {{ __($savedImage ? 'skins::profile.saved_png' : ($shownImage ? 'skins::profile.default_png' : 'skins::profile.no_'.$kind)) }}
                                        </p>
                                    </div>
                                </section>
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($showVisibility || $showGamePolicy)
                    <div class="border-top pt-3">
                        @if ($showVisibility)
                            <p @class(['small', 'text-body-secondary', 'mb-2' => $showGamePolicy, 'mb-0' => !$showGamePolicy])>
                                {{ __($canReplaceAny ? ($publicSharing ? 'skins::profile.public_policy' : 'skins::profile.private_policy') : 'skins::profile.public_links_policy') }}
                            </p>
                        @endif
                        @if ($showGamePolicy)
                            <p class="small text-body-secondary mb-0">{{ __('skins::profile.game_policy') }}</p>
                        @endif
                    </div>
                @endif
            </div>
        @if ($canEdit)
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('skins::profile.save') }}</button>
            </div>
            </form>
        @endif
    @endif
</section>
