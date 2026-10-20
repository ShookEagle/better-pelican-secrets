<?php

use App\Http\Middleware\Api\Daemon\DaemonAuthenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use ShookEagle\BetterPelicanSecrets\Node\ApplicationReportController;
use ShookEagle\BetterPelicanSecrets\Node\RequireSecretNode;
use ShookEagle\BetterPelicanSecrets\Node\RuntimeBundleController;

Route::middleware([RequireSecretNode::class, DaemonAuthenticate::class, SubstituteBindings::class])
    ->prefix('/api/remote/servers/{server:uuid}/secrets')
    ->group(function () {
        Route::post('/runtime-bundle', RuntimeBundleController::class)->name('bps.secrets.runtime-bundle');
        Route::post('/application-report', ApplicationReportController::class)->name('bps.secrets.application-report');
    });
