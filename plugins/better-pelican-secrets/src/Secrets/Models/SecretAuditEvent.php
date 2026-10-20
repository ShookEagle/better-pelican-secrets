<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class SecretAuditEvent extends Model
{
    protected $table = 'bps_secret_audit_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainException('Audit events are immutable.'));
    }
}
