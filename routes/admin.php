<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Skins\Http\Controllers\SettingsController;
use KraftInk\Plugins\Skins\Http\Controllers\SkinsController;

Route::prefix('admin/plugins/skins')->name('plugins.skins.admin.')
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:skins.manage'])
    ->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::get('/default-skin.png', [SkinsController::class, 'defaultPreview'])->name('default-image');
        Route::post('/', [SettingsController::class, 'update'])->name('update');
    });
