<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class SecretVersion extends Model
{
    protected $table = 'bps_secret_versions';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['encrypted_value'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainException('Secret versions are immutable.'));
    }
}
