<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\Server;
use DomainException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Node\DesiredPlan;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Node\Protocol;
use ShookEagle\BetterPelicanSecrets\Node\StartupPlan;
use ShookEagle\BetterPelicanSecrets\Plans\Models\DesiredPlanRecord;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretVersionRef;

final class PlanService
{
    public function current(Server $server): DesiredPlan
    {
        return DB::transaction(function () use ($server) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $row = DesiredPlanRecord::query()->where('server_id', $server->id)->orderByDesc('id')->first();
            $revision = $row?->revision ?? '0';
            $candidate = $this->referencePlan($server, $revision);
            if (!$row) {
                return $candidate->files || $candidate->startup ? $this->persist($server, $candidate) : $candidate;
            }
            $stored = Protocol::decodePlan($row->payload);
            if ($stored->serverUuid !== $server->uuid || $stored->revision !== $revision) {
                throw new DomainException('plan_invalid');
            }

            // Reconcile authoritative references even if an after-commit notification was lost.
            return $candidate->toWire() === $stored->toWire() ? $stored : $this->persist($server, $candidate);
        }, 3);
    }

    public function snapshot(Server $server, string $expectedRevision): DesiredPlan
    {
        $plan = $this->current($server);
        if ($plan->revision !== $expectedRevision) {
            throw new DomainException('revision_conflict');
        }

        return $plan;
    }

    public function rebuild(Server $server): DesiredPlan
    {
        return DB::transaction(function () use ($server) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $revision = DesiredPlanRecord::query()->where('server_id', $server->id)->orderByDesc('id')->value('revision') ?? '0';

            return $this->persist($server, $this->referencePlan($server, (string) $revision));
        }, 3);
    }

    public function markSecretChanged(string $secretId): void
    {
        $secret = Secret::query()->find($secretId);
        if ($secret && ($server = Server::query()->find($secret->server_id))) {
            $this->current($server);
        }
    }

    private function referencePlan(Server $server, string $revision): DesiredPlan
    {
        $files = ManagedFile::query()->where('server_id', $server->id)->orderBy('id')->get()->map(fn ($file) => new FilePlan($file->id, $file->source, $file->destination, $file->format, $file->default_mode, $file->manifest))->all();
        $startup = StartupBinding::query()->where('server_id', $server->id)->orderBy('env')->get()->map(fn ($binding) => new StartupPlan($binding->env, $binding->secret_id))->all();
        $ids = [];
        foreach ($files as $file) {
            foreach ($file->manifest as $entry) {
                $ids[] = $entry['secret_id'];
            }
        }
        foreach ($startup as $binding) {
            $ids[] = $binding->secretId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $secrets = Secret::query()->where('server_id', $server->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $versions = [];
        foreach ($ids as $id) {
            if (!isset($secrets[$id])) {
                throw new DomainException('secret_missing');
            }
            $versions[] = new SecretVersionRef($id, $secrets[$id]->current_version);
        }

        return Protocol::decodePlan((new DesiredPlan(1, $server->uuid, $revision, $versions, $files, $startup, Protocol::LIMITS))->toWire());
    }

    private function persist(Server $server, DesiredPlan $candidate): DesiredPlan
    {
        $payload = $candidate->toWire();
        $payload['revision'] = $this->increment($candidate->revision);
        $plan = Protocol::decodePlan($payload);
        DesiredPlanRecord::query()->create(['server_id' => $server->id, 'revision' => $plan->revision,
            'payload' => $plan->toWire(), 'created_at' => now()]);

        return $plan;
    }

    private function increment(string $revision): string
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,19})\z/', $revision) || (strlen($revision) === 20 && strcmp($revision, '18446744073709551615') >= 0)) {
            throw new DomainException('revision_exhausted');
        }
        for ($i = strlen($revision) - 1; $i >= 0; $i--) {
            if ($revision[$i] !== '9') {
                $revision[$i] = chr(ord($revision[$i]) + 1);

                return $revision;
            }
            $revision[$i] = '0';
        }

        return '1' . $revision;
    }
}
