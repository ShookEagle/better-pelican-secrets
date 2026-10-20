<?php

namespace ShookEagle\BetterPelicanSecrets\Plans\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class DesiredPlanRecord extends Model
{
    protected $table = 'bps_desired_plan_records';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['server_id' => 'integer', 'revision' => 'string', 'payload' => 'array'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainException('Desired plans are immutable.'));
    }
}
