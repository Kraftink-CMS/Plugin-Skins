<?php

namespace KraftInk\Plugins\Skins\Models;

use Illuminate\Database\Eloquent\Model;

class ServerIntegration extends Model
{
    protected $table = 'skins__server_integrations';

    protected $primaryKey = 'server_id';

    protected $guarded = [];

    protected $hidden = ['connector_token_hash'];

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sync_enabled' => 'boolean', 'polled_at' => 'immutable_datetime'];
    }
}
