<?php

namespace KraftInk\Plugins\Skins\Models;

use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Synchronization extends Model
{
    protected $table = 'skins__synchronizations';

    protected $guarded = ['id'];

    protected $hidden = ['connector_token_hash', 'target_fingerprint', 'last_auto_fingerprint'];

    protected function casts(): array
    {
        return ['managed' => 'boolean', 'expires_at' => 'immutable_datetime'];
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function scopeForConnector(Builder $query, Server $server): void
    {
        $query->where('server_id', $server->id)->where('connector_token_hash', $server->token_hash);
    }

    public function scopeOnCurrentConnector(Builder $query): void
    {
        $query->whereExists(Server::query()->linkedKraftLink('minecraft')->select('id')
            ->whereColumn('servers.id', 'skins__synchronizations.server_id')
            ->whereColumn('servers.token_hash', 'skins__synchronizations.connector_token_hash'));
    }

    public function isPendingFor(Server $server): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture() &&
            $this->connector_token_hash === $server->token_hash;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
