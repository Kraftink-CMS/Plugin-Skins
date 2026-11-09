<?php

namespace KraftInk\Plugins\Skins\Models;

use App\Models\Accounts\GameIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    protected $table = 'skins__profiles';

    protected $guarded = ['id'];

    protected $attributes = ['variant' => 'classic', 'skin_public' => false, 'cape_public' => false, 'auto_sync' => false];

    protected $hidden = ['skin_path', 'cape_path'];

    protected function casts(): array
    {
        return [
            'skin_public' => 'boolean',
            'cape_public' => 'boolean',
            'auto_sync' => 'boolean',
        ];
    }

    public function synchronizations(): HasMany
    {
        return $this->hasMany(Synchronization::class);
    }

    public function scopeWithCurrentIdentity(Builder $query): void
    {
        $query->whereExists(self::verifiedIdentity());
    }

    public static function verifiedIdentity(): Builder
    {
        return GameIdentity::query()->select('id')
            ->whereColumn('game_identities.user_id', 'skins__profiles.user_id')
            ->whereColumn('game_identities.uuid', 'skins__profiles.minecraft_uuid')
            ->where('game', 'minecraft')->whereNotNull('verified_at');
    }
}
