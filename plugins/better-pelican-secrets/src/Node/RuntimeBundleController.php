<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use App\Models\Node;
use App\Models\Server;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ApplicationRecord;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use Throwable;

final class RuntimeBundleController
{
    public function __construct(private PlanService $plans, private SecretStore $store) {}

    public function __invoke(Request $request, Server $server): JsonResponse
    {
        try {
            $body = (array) NodeRequestBody::read($request, 4096);
            if (count($body) !== 3 || array_diff(array_keys($body), ['protocol', 'expected_revision', 'attempt_id'])) {
                throw new NodeRequestError('protocol_invalid');
            }
            if (($body['protocol'] ?? null) !== 1) {
                throw new NodeRequestError('protocol_unsupported');
            }
            Protocol::decodePlan(['protocol' => 1, 'server_uuid' => $server->uuid, 'revision' => $body['expected_revision'] ?? null,
                'versions' => [], 'files' => [], 'startup' => [], 'limits' => Protocol::LIMITS]);
            if (!is_string($body['attempt_id'] ?? null) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $body['attempt_id'])) {
                throw new NodeRequestError('protocol_invalid');
            }

            return DB::transaction(function () use ($request, $server, $body) {
                $server = Server::query()->lockForUpdate()->findOrFail($server->id);
                $node = $request->attributes->get('node');
                if (!$node instanceof Node || $node->id !== $server->node_id) {
                    throw new NodeRequestError('forbidden', 403);
                }
                $plan = $this->plans->snapshot($server, $body['expected_revision']);
                $issued = ApplicationRecord::query()->where('server_id', $server->id)->where('attempt_id', $body['attempt_id'])->where('kind', 'issued')->first();
                if ($issued && ($issued->revision !== $plan->revision || $issued->node_id !== $node->id)) {
                    throw new NodeRequestError('revision_conflict', 409);
                }
                $values = [];
                $bytes = strlen(json_encode([...$plan->toWire(), 'attempt_id' => $body['attempt_id'], 'values' => (object) []], JSON_THROW_ON_ERROR));
                if ($bytes > $plan->limits['bundle_bytes']) {
                    throw new NodeRequestError('limit_exceeded', 503);
                }
                foreach ($plan->versions as $reference) {
                    $value = $this->store->reveal($reference);
                    $bytes += strlen(json_encode($reference->secretId, JSON_THROW_ON_ERROR)) + 1
                        + strlen(json_encode($value->bytes(), JSON_THROW_ON_ERROR)) + (count($values) ? 1 : 0);
                    if ($bytes > $plan->limits['bundle_bytes']) {
                        throw new NodeRequestError('limit_exceeded', 503);
                    }
                    $values[$reference->secretId] = $value;
                }
                $bundle = new RuntimeBundle($plan, $body['attempt_id'], $values);
                $response = new JsonResponse($bundle->toWire(), 200, ['Cache-Control' => 'no-store'], JSON_THROW_ON_ERROR);
                if (strlen($response->getContent()) > $plan->limits['bundle_bytes']) {
                    throw new NodeRequestError('limit_exceeded', 503);
                }
                if (!$issued) {
                    $metadata = $plan->toWire();
                    ApplicationRecord::query()->create(['server_id' => $server->id, 'node_id' => $node->id,
                        'attempt_id' => $body['attempt_id'], 'revision' => $plan->revision, 'kind' => 'issued',
                        'payload_digest' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)), 'payload' => $metadata, 'created_at' => now()]);
                }

                return $response;
            }, 3);
        } catch (NodeRequestError $error) {
            return $this->error($error->errorCode, $error->status);
        } catch (DomainException $error) {
            if ($error->getMessage() === 'revision_conflict') {
                return $this->error('revision_conflict', 409);
            }
            if (in_array($error->getMessage(), ['protocol_invalid', 'protocol_unsupported', 'limit_exceeded'], true)) {
                return $this->error($error->getMessage(), $error->getMessage() === 'limit_exceeded' ? 503 : 422);
            }

            return $this->error('decryption_failed', 503);
        } catch (Throwable) {
            return $this->error('internal_error', 503);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
