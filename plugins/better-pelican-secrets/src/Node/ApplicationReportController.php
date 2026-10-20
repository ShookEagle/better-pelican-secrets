<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use App\Models\Node;
use App\Models\Server;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ApplicationRecord;
use ShookEagle\BetterPelicanSecrets\Plans\Models\DesiredPlanRecord;
use stdClass;
use Throwable;

final class ApplicationReportController
{
    public function __construct(private ApplicationStatusService $status) {}

    public function __invoke(Request $request, Server $server): JsonResponse
    {
        try {
            $object = NodeRequestBody::read($request, Protocol::LIMITS['bundle_bytes']);
            if (!($object->source_digests ?? null) instanceof stdClass || !is_array($object->versions ?? null)) {
                throw new NodeRequestError('protocol_invalid');
            }
            $report = Protocol::decodeReport(NodeRequestBody::arrays($object));

            return DB::transaction(function () use ($request, $server, $report) {
                $server = Server::query()->lockForUpdate()->findOrFail($server->id);
                $node = $request->attributes->get('node');
                if (!$node instanceof Node || $node->id !== $server->node_id || $report->serverUuid !== $server->uuid) {
                    throw new NodeRequestError('forbidden', 403);
                }
                $issued = ApplicationRecord::query()->where('server_id', $server->id)->where('node_id', $node->id)
                    ->where('attempt_id', $report->attemptId)->where('kind', 'issued')->first();
                $planWire = $issued?->payload;
                if (!$planWire && $report->kind === 'failed') {
                    $planWire = DesiredPlanRecord::query()->where('server_id', $server->id)->where('revision', $report->revision)->value('payload');
                    if (is_string($planWire)) {
                        $planWire = json_decode($planWire, true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                if (!$planWire) {
                    throw new NodeRequestError('revision_conflict', 409);
                }
                $plan = Protocol::decodePlan($planWire);
                if ($plan->revision !== $report->revision) {
                    throw new NodeRequestError('revision_conflict', 409);
                }
                $payload = $report->toWire();
                // Stable source content is deduplicated independently of the
                // observation counter, which is kept on the current head.
                if ($report->kind === 'source-dirty') {
                    unset($payload['observation_sequence']);
                }
                $digests = $report->sourceDigests;
                ksort($digests);
                $payload['source_digests'] = $digests ?: (object) [];
                usort($payload['versions'], fn ($a, $b) => strcmp($a['secret_id'], $b['secret_id']));
                $expectedVersions = $plan->toWire()['versions'];
                usort($expectedVersions, fn ($a, $b) => strcmp($a['secret_id'], $b['secret_id']));
                if ($payload['versions'] !== $expectedVersions) {
                    throw new NodeRequestError('protocol_invalid');
                }
                $fileIds = array_map(fn ($file) => $file->fileId, $plan->files);
                sort($fileIds);
                $digestIds = array_keys($digests);
                sort($digestIds);
                if (array_diff($digestIds, $fileIds) || ($report->kind === 'applied' && $digestIds !== $fileIds)) {
                    throw new NodeRequestError('protocol_invalid');
                }
                $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $terminal = ApplicationRecord::query()->where('server_id', $server->id)->where('attempt_id', $report->attemptId)
                    ->whereIn('kind', ['applied', 'failed'])->first();
                if ($report->kind === 'source-dirty') {
                    if (!$terminal || $terminal->kind !== 'applied') {
                        throw new NodeRequestError('revision_conflict', 409);
                    }
                } elseif ($terminal && ($terminal->kind !== $report->kind || $terminal->payload_digest !== $digest)) {
                    throw new NodeRequestError('revision_conflict', 409);
                }
                $record = ApplicationRecord::query()->firstOrCreate(['server_id' => $server->id, 'attempt_id' => $report->attemptId,
                    'kind' => $report->kind, 'payload_digest' => $digest],
                    ['node_id' => $node->id, 'revision' => $report->revision, 'payload' => $payload, 'created_at' => now()]);

                $this->status->recordObservation($record, $report->observationSequence);
                $status = $this->status->forServer($server);

                return new JsonResponse(['accepted' => true, 'desired_revision' => $status->desiredRevision, 'applied_revision' => $status->appliedRevision, 'pending' => $status->pending, 'dirty' => $status->dirty], 200, ['Cache-Control' => 'no-store']);
            }, 3);
        } catch (NodeRequestError $error) {
            return new JsonResponse(['error' => $error->errorCode], $error->status, ['Cache-Control' => 'no-store']);
        } catch (DomainException) {
            return new JsonResponse(['error' => 'protocol_invalid'], 422, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'internal_error'], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
