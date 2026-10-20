<?php

namespace ShookEagle\BetterPelicanSecrets\Http\Controllers;

use App\Models\Server;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\GrantActions;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\SecretActions;

final class SecretController
{
    public function __construct(private SecretActions $actions, private GrantActions $grants) {}

    /** Catch before Laravel's route pipeline renders errors or flashes input. */
    public function callAction(string $method, array $parameters): JsonResponse
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (AuthorizationException) {
            return $this->json(['error' => 'secret_access_denied'], 403);
        } catch (DomainException) {
            return $this->json(['error' => 'secret_action_failed'], 422);
        }
    }

    public function index(Request $request, Server $server): JsonResponse
    {
        return $this->json(['data' => $this->actions->listing($request->user(), $server)]);
    }

    public function create(Request $request, Server $server): JsonResponse
    {
        $data = $this->input($request, ['name', 'description', 'value', 'mode']);

        return $this->json(['data' => $this->actions->create($request->user(), $server,
            $this->string($data, 'name'), $this->string($data, 'description'), $this->string($data, 'value'),
            $this->string($data, 'mode', 'manual'))], 201);
    }

    public function update(Request $request, Server $server, string $secretId): JsonResponse
    {
        $data = $this->input($request, ['value', 'description']);
        $result = DB::transaction(function () use ($request, $server, $secretId, $data) {
            $result = $this->actions->replace($request->user(), $server, $secretId, $this->string($data, 'value'));
            if (array_key_exists('description', $data)) {
                $result = $this->actions->description($request->user(), $server, $secretId, $this->string($data, 'description'));
            }

            return $result;
        }, 3);

        return $this->json(['data' => $result]);
    }

    public function reveal(Request $request, Server $server, string $secretId): JsonResponse
    {
        $this->input($request, []);

        return $this->json(['value' => $this->actions->reveal($request->user(), $server, $secretId)->bytes()]);
    }

    public function generate(Request $request, Server $server, string $secretId): JsonResponse
    {
        $this->input($request, []);

        return $this->json(['data' => $this->actions->generate($request->user(), $server, $secretId)]);
    }

    public function empty(Request $request, Server $server, string $secretId): JsonResponse
    {
        $this->input($request, []);

        return $this->json(['data' => $this->actions->setEmpty($request->user(), $server, $secretId)]);
    }

    public function delete(Request $request, Server $server, string $secretId): JsonResponse
    {
        $this->input($request, []);
        $this->actions->delete($request->user(), $server, $secretId);

        return $this->json(['deleted' => true]);
    }

    public function access(Request $request, Server $server, ?string $secretId = null): JsonResponse
    {
        return $this->json($this->grants->listing($request->user(), $server, $secretId));
    }

    public function grant(Request $request, Server $server, ?string $secretId = null): JsonResponse
    {
        return $this->changeGrant($request, $server, $secretId, false);
    }

    public function revoke(Request $request, Server $server, ?string $secretId = null): JsonResponse
    {
        return $this->changeGrant($request, $server, $secretId, true);
    }

    private function changeGrant(Request $request, Server $server, ?string $secretId, bool $revoke): JsonResponse
    {
        $data = $this->input($request, ['user_id', 'operations']);
        if (!is_int($data['user_id'] ?? null) || !is_array($data['operations'] ?? null)) {
            throw new DomainException('Invalid access request.');
        }
        $this->grants->change($request->user(), $server, $secretId, $data['user_id'], $data['operations'], $revoke);

        return $this->json(['saved' => true]);
    }

    /** Decode the original body: trimming and empty-to-null conversion must not change values. */
    private function input(#[\SensitiveParameter] Request $request, array $allowed): array
    {
        $body = $request->getContent();
        if (strlen($body) > 524288) {
            throw new DomainException('Invalid request.');
        }
        if ($body === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Invalid request.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || array_diff(array_keys($data), $allowed)) {
            throw new DomainException('Invalid request.');
        }

        return $data;
    }

    private function string(#[\SensitiveParameter] array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (array_key_exists($key, $data) && !is_string($data[$key])) {
            throw new DomainException('Invalid request.');
        }

        return $value;
    }

    private function json(#[\SensitiveParameter] array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
