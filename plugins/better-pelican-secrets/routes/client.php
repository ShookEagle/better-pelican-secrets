<?php

use App\Http\Middleware\Activity\ServerSubject;
use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Illuminate\Support\Facades\Route;
use ShookEagle\BetterPelicanSecrets\Http\Controllers\SecretController;
use ShookEagle\BetterPelicanSecrets\Http\SecretResponse;

Route::middleware([SecretResponse::class, 'api', 'client-api', 'throttle:api.client',
    ServerSubject::class, AuthenticateServerAccess::class, ResourceBelongsToServer::class])
    ->prefix('/api/client/servers/{server:uuid}/secrets')->scopeBindings()->group(function () {
        Route::get('/', [SecretController::class, 'index']);
        Route::post('/', [SecretController::class, 'create']);
        Route::get('/grants', [SecretController::class, 'access']);
        Route::post('/grants', [SecretController::class, 'grant']);
        Route::delete('/grants', [SecretController::class, 'revoke']);
        Route::patch('/{secretId}', [SecretController::class, 'update'])->whereUuid('secretId');
        Route::delete('/{secretId}', [SecretController::class, 'delete'])->whereUuid('secretId');
        Route::post('/{secretId}/reveal', [SecretController::class, 'reveal'])->whereUuid('secretId');
        Route::post('/{secretId}/empty', [SecretController::class, 'empty'])->whereUuid('secretId');
        Route::post('/{secretId}/generate', [SecretController::class, 'generate'])->whereUuid('secretId');
        Route::get('/{secretId}/grants', [SecretController::class, 'access'])->whereUuid('secretId');
        Route::post('/{secretId}/grants', [SecretController::class, 'grant'])->whereUuid('secretId');
        Route::delete('/{secretId}/grants', [SecretController::class, 'revoke'])->whereUuid('secretId');
    });
