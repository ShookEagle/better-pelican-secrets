<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class Secret extends Model
{
    protected $table = 'bps_secrets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['server_id' => 'integer', 'current_version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $secret) {
            if ($secret->isDirty(['id', 'name', 'server_id'])) {
                throw new DomainException('Secret identity is immutable.');
            }
        });
    }
}
