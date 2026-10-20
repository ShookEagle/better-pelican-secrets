<?php

namespace ShookEagle\BetterPelicanSecrets\Http;

use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecretResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (AuthorizationException) {
            $response = response()->json(['error' => 'secret_access_denied'], 403);
        } catch (DomainException) {
            $response = response()->json(['error' => 'secret_action_failed'], 422);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
