<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Skins\Http\Controllers\SkinsController;

Route::post('/account/skins', [SkinsController::class, 'update'])
    ->middleware(['web', 'auth', 'permission:skins.use', 'throttle:10,1'])->name('plugins.skins.update');

Route::get('/skins/{uuid}/{kind}.png', [SkinsController::class, 'image'])
    ->where(['uuid' => '[a-fA-F0-9-]{32,36}', 'kind' => 'skin|cape'])
    ->middleware(['web', 'throttle:60,1'])->name('plugins.skins.image');
