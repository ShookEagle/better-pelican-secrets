<?php

use ShookEagle\BetterPelicanSecrets\Node\Protocol;

// Trusted operator limits may only lower the protocol ceilings.
return [
    'audit_retention_days' => (int) env('BPS_AUDIT_RETENTION_DAYS', 90),
    'limits' => [
        ...Protocol::LIMITS,
        'bundle_bytes' => (int) env('BPS_BUNDLE_BYTES', 16777216),
        'rendered_bytes' => (int) env('BPS_RENDERED_BYTES', 67108864),
    ],
];
