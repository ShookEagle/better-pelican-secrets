<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets\Models;

use Illuminate\Database\Eloquent\Model;

final class SecretGrant extends Model
{
    protected $table = 'bps_secret_grants';

    public $timestamps = false;

    protected $guarded = [];
}
