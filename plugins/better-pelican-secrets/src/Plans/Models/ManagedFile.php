<?php

namespace ShookEagle\BetterPelicanSecrets\Plans\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagedFile extends Model
{
    protected $table = 'bps_managed_files';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'server_id' => 'integer'];
    }
}
