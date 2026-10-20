<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ApplicationRecord;

/** Metadata-only reachability, including source nodes not yet safely retired. */
final class RuntimeReferences
{
    public function applied(Server $server): array
    {
        $retired = DB::table('bps_node_retirements')->where('server_id', $server->id)->pluck('through_record_id', 'node_id');
        $issued = ApplicationRecord::where('server_id', $server->id)->where('kind', 'issued')->get()->keyBy('attempt_id');
        $reports = ApplicationRecord::where('server_id', $server->id)->where('kind', 'applied')->get()
            ->filter(fn ($report) => isset($issued[$report->attempt_id]) && $issued[$report->attempt_id]->id > ($retired[$report->node_id] ?? 0))
            ->sortByDesc(fn ($report) => $issued[$report->attempt_id]->id)->unique('node_id');

        return $reports->values()->all();
    }

    public function versions(Server $server): array
    {
        $retired = DB::table('bps_node_retirements')->where('server_id', $server->id)->pluck('through_record_id', 'node_id');
        $all = ApplicationRecord::where('server_id', $server->id)->get();
        $issued = $all->where('kind', 'issued')->keyBy('attempt_id');
        $applied = $this->applied($server);
        $latest = [];
        $versions = [];
        foreach ($applied as $report) {
            $latest[$report->node_id] = $issued[$report->attempt_id]->id;
            $versions = [...$versions, ...$report->payload['versions']];
        }
        foreach ($issued as $attempt) {
            if ($attempt->id <= max($retired[$attempt->node_id] ?? 0, $latest[$attempt->node_id] ?? 0)
                || $all->contains(fn ($record) => $record->attempt_id === $attempt->attempt_id && $record->kind === 'failed')) {
                continue;
            }
            $versions = [...$versions, ...$attempt->payload['versions']];
        }

        return collect($versions)->unique(fn ($ref) => $ref['secret_id'] . ':' . $ref['version'])->values()->all();
    }

    public function usesSecret(Server $server, string $id): bool
    {
        return array_any($this->versions($server), fn ($ref) => $ref['secret_id'] === $id);
    }

    /** Native transfer success is received after the source was stopped and blocked from restarting. */
    public function retireSource(Server $server, int $nodeId): void
    {
        $through = ApplicationRecord::where('server_id', $server->id)->where('node_id', $nodeId)->max('id') ?? 0;
        DB::table('bps_node_retirements')->updateOrInsert(['server_id' => $server->id, 'node_id' => $nodeId],
            ['through_record_id' => $through, 'updated_at' => now()]);
    }
}
