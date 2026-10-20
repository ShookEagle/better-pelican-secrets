<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RequireSecretNode
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!$request->isSecure() || !$request->bearerToken()) {
            return $this->denied();
        }
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            return $this->denied();
        } catch (Throwable) {
            return new JsonResponse(['error' => 'internal_error'], 503, ['Cache-Control' => 'no-store']);
        }
    }

    private function denied(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden'], 403, ['Cache-Control' => 'no-store']);
    }
}
