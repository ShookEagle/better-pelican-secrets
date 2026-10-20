<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\Server;
use DomainException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Node\ApplicationStatus;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ApplicationRecord;

final class ApplicationStatusService
{
    public function __construct(private PlanService $plans) {}

    public function forServer(Server $server): ApplicationStatus
    {
        return DB::transaction(function () use ($server) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);

            return $this->statusLocked($server);
        }, 3);
    }

    private function statusLocked(Server $server): ApplicationStatus
    {
        $desired = $this->plans->current($server);
        $applied = $this->latestApplied($server);
        $terminal = ApplicationRecord::query()->from('bps_application_records as reports')
            ->leftJoin('bps_application_records as issued', function ($join) {
                $join->on('issued.server_id', '=', 'reports.server_id')->on('issued.node_id', '=', 'reports.node_id')
                    ->on('issued.attempt_id', '=', 'reports.attempt_id')->where('issued.kind', 'issued');
            })->where('reports.server_id', $server->id)->where('reports.node_id', $server->node_id)
            ->whereIn('reports.kind', ['applied', 'failed'])->select('reports.*')
            ->orderByRaw('COALESCE(issued.id, reports.id) DESC')->first();
        $dirty = false;
        $error = $terminal?->kind === 'failed' ? $terminal->payload['error_code'] : null;
        if ($applied) {
            $observed = $this->observation($applied);
            if ($observed) {
                $actual = $observed->payload['source_digests'];
                $expected = $applied->payload['source_digests'];
                ksort($actual);
                ksort($expected);
                $dirty = $actual !== $expected;
                if (array_keys($actual) !== array_keys($expected)) {
                    $error = 'template_invalid';
                }
            }
        }
        $configured = $desired->revision !== '0' || $desired->files || $desired->startup;
        $pending = $dirty || ($applied ? $desired->revision !== $applied->revision : (bool) $configured);

        return new ApplicationStatus($desired->revision, $applied?->revision, $pending, $dirty, $terminal?->attempt_id, $error);
    }

    public function recordObservation(ApplicationRecord $record, ?string $nodeSequence = null): void
    {
        if ($record->kind !== 'source-dirty') {
            return;
        }
        $key = ['server_id' => $record->server_id, 'attempt_id' => $record->attempt_id];
        $values = ['application_record_id' => $record->id, 'updated_at' => now()];
        if ($nodeSequence !== null) {
            $head = DB::table('bps_source_heads')->where($key)->lockForUpdate()->first();
            $sequence = $nodeSequence;
            $previous = (string) ($head?->node_sequence ?? '0');
            $order = strlen($sequence) <=> strlen($previous);
            if ($order === 0) {
                $order = strcmp($sequence, $previous);
            }
            if ($order < 0) {
                return;
            }
            if ($order === 0) {
                $existing = $head?->node_record_id ? ApplicationRecord::query()->find($head->node_record_id) : null;
                if (!$existing || $existing->payload_digest !== $record->payload_digest) {
                    throw new DomainException('protocol_invalid');
                }

                return;
            }
            $values['node_sequence'] = $sequence;
            $values['node_record_id'] = $record->id;
        }
        DB::table('bps_source_heads')->updateOrInsert($key, $values);
    }

    public function recordSourceSave(Server $server, string $fileId, string $digest): void
    {
        $applied = $this->latestApplied($server);
        if (!$applied || !array_key_exists($fileId, $applied->payload['source_digests'])) {
            return;
        }
        $payload = ($this->observation($applied) ?? $applied)->payload;
        $payload['kind'] = 'source-dirty';
        $payload['source_digests'][$fileId] = $digest;
        ksort($payload['source_digests']);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $record = ApplicationRecord::query()->firstOrCreate(
            ['server_id' => $server->id, 'attempt_id' => $applied->attempt_id, 'kind' => 'source-dirty', 'payload_digest' => $hash],
            ['node_id' => $server->node_id, 'revision' => $applied->revision, 'payload' => $payload, 'created_at' => now()]);
        $this->recordObservation($record);
    }

    private function latestApplied(Server $server): ?ApplicationRecord
    {
        // Bundle issuance orders attempts; delayed delivery of an old applied
        // report must not replace the more recently issued successful start.
        return ApplicationRecord::query()->from('bps_application_records as reports')
            ->join('bps_application_records as issued', function ($join) {
                $join->on('issued.server_id', '=', 'reports.server_id')->on('issued.node_id', '=', 'reports.node_id')
                    ->on('issued.attempt_id', '=', 'reports.attempt_id')->where('issued.kind', 'issued');
            })->where('reports.server_id', $server->id)->where('reports.node_id', $server->node_id)
            ->where('reports.kind', 'applied')->select('reports.*')->orderByDesc('issued.id')->first();
    }

    private function observation(ApplicationRecord $applied): ?ApplicationRecord
    {
        $id = DB::table('bps_source_heads')->where('server_id', $applied->server_id)
            ->where('attempt_id', $applied->attempt_id)->value('application_record_id');

        return $id ? ApplicationRecord::query()->where('server_id', $applied->server_id)->where('node_id', $applied->node_id)
            ->where('attempt_id', $applied->attempt_id)->where('revision', $applied->revision)->where('kind', 'source-dirty')->find($id) : null;
    }
}
