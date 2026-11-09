<?php

namespace KraftInk\Plugins\Skins\Providers;

use App\Events\ApiProfileBuilding;
use App\Events\PruningData;
use App\Kraft\Auth\ApiScopes;
use App\Kraft\Auth\Avatars\AvatarProviders;
use App\Kraft\Extensions\SlotRegistry;
use App\Kraft\Link\LinkPollRegistry;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use KraftInk\Plugins\Skins\Http\Controllers\SkinsController;
use KraftInk\Plugins\Skins\Services\Profiles;
use KraftInk\Plugins\Skins\Services\ServerSkins;
use KraftInk\Plugins\Skins\Services\SkinAvatarProvider;
use KraftInk\Plugins\Skins\Services\TextureFiles;

class PluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'skins');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'skins');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen(ApiProfileBuilding::class, [TextureFiles::class, 'contributeToApiProfile']);
        Event::listen(PruningData::class, [Profiles::class, 'prune']);
        app(SlotRegistry::class)->add('skins', 'account.sections', 'appearance', static function (
            array $context,
            SkinsController $controller,
            TextureFiles $files,
        ): View|string {
            $user = $context['user'] ?? null;

            return $user instanceof User ? $controller->panel($user, $files) : '';
        }, permission: 'skins.use');
        if (function_exists('imagecreatefromstring')) {
            app(AvatarProviders::class)->register('minecraft', 'skins', SkinAvatarProvider::class);
        }
        $scopes = app(ApiScopes::class);
        $scopes->register('skins', 'skins:read', 'skins::api.read_label', 'skins::api.read_description', ['skins.use'], serviceAccounts: false);
        $scopes->register('skins', 'skins:write', 'skins::api.write_label', 'skins::api.write_description', ['skins.use', 'skins.upload'], serviceAccounts: false);
        app(LinkPollRegistry::class)->register('skins.pending', ServerSkins::class);
    }
}
