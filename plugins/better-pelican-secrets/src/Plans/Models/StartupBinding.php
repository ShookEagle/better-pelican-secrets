<?php

namespace ShookEagle\BetterPelicanSecrets\Plans\Models;

use Illuminate\Database\Eloquent\Model;

final class StartupBinding extends Model
{
    protected $table = 'bps_startup_bindings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['server_id' => 'integer'];
    }
}
