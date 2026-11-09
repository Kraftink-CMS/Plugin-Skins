<?php

use App\Http\Middleware\ApiAvailability;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Skins\Http\Controllers\TextureApiController;

Route::prefix('api/skins')->name('plugins.skins.api.')->middleware('api')->group(function () {
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/profiles/{player}', [TextureApiController::class, 'profile'])
            ->where('player', '[a-zA-Z0-9_-]{1,36}')->name('profile');
        Route::get('/profiles/{player}/textures/{kind}.png', [TextureApiController::class, 'texture'])
            ->where(['player' => '[a-zA-Z0-9_-]{1,36}', 'kind' => 'skin|cape'])->name('texture');
        Route::get('/profiles/{player}/renders/{view}.png', [TextureApiController::class, 'render'])
            ->where(['player' => '[a-zA-Z0-9_-]{1,36}', 'view' => 'face|body|combo'])->name('render');
        Route::get('/default.png', [TextureApiController::class, 'defaultImage'])->name('default');
    });

    Route::prefix('me')->middleware([ApiAvailability::class, 'throttle:60,1'])->group(function () {
        Route::get('/profile', [TextureApiController::class, 'own'])->middleware(AuthenticateApiToken::class.':skins:read')
            ->name('own');
        Route::get('/textures/{kind}.png', [TextureApiController::class, 'ownTexture'])
            ->where('kind', 'skin|cape')->middleware(AuthenticateApiToken::class.':skins:read')->name('own-texture');
        Route::post('/textures/{kind}', [TextureApiController::class, 'upload'])
            ->where('kind', 'skin|cape')->middleware([AuthenticateApiToken::class.':skins:write', 'throttle:10,1'])->name('upload');
    });
});
