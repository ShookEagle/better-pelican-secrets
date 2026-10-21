<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Node\DesiredPlan;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use Throwable;

final class MigrationService
{
    public function __construct(private TemplateSourceService $sources, private BindingService $bindings, private DaemonFileRepository $files) {}

    public function migrate(User $actor, Server $server, string $expectedDigest, #[\SensitiveParameter] string $replacementSource, FilePlan $file, array $confirmedReferences): DesiredPlan
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('setup_transaction_active');
        }
        $expected = array_map(fn ($entry) => ['secret_id' => $entry['secret_id'], 'mode' => $entry['mode']], $file->manifest);
        $canonical = static function (array $entries): array {
            foreach ($entries as $entry) {
                if (!is_array($entry) || array_diff(array_keys($entry), ['secret_id', 'mode']) || count($entry) !== 2
                    || !is_string($entry['secret_id'] ?? null) || !is_string($entry['mode'] ?? null)) {
                    throw new DomainException('migration_confirmation_required');
                }
            }
            usort($entries, fn ($a, $b) => [$a['secret_id'], $a['mode']] <=> [$b['secret_id'], $b['mode']]);

            return $entries;
        };
        if (!$expected || $canonical($expected) !== $canonical($confirmedReferences)) {
            throw new DomainException('migration_confirmation_required');
        }
        // Complete local validation/auth before touching the remote file.
        $this->bindings->preflightFile($actor, $server, $file, $replacementSource);
        $current = $this->sources->readPath($actor, $server, $file->source, true);
        $actual = $current === null ? 'absent' : hash('sha256', $current);
        if (!hash_equals($actual, $expectedDigest)) {
            throw new DomainException('source_conflict');
        }
        $attempt = (string) Str::uuid();
        // This intent must commit before the first remote side effect. If the
        // worker disappears or the database fails later, it still vetoes starts.
        DB::transaction(function () use ($server, $file, $attempt) {
            Server::query()->lockForUpdate()->findOrFail($server->id);
            DB::table('bps_setup_failures')->updateOrInsert(
                ['server_id' => $server->id, 'file_id' => $file->fileId],
                ['source' => $file->source, 'attempt_id' => $attempt, 'error_code' => 'template_invalid', 'updated_at' => now()]);
        }, 3);
        try {
            return DB::transaction(function () use ($actor, $server, $file, $replacementSource, $attempt) {
                $server = Server::query()->lockForUpdate()->findOrFail($server->id);
                $intent = DB::table('bps_setup_failures')->where('server_id', $server->id)->where('file_id', $file->fileId)->first();
                if (!$intent || $intent->attempt_id !== $attempt) {
                    throw new DomainException('source_conflict');
                }
                // The server lock serializes competing setup intents through
                // their writes; only this attempt may resolve its durable guard.
                $this->files->setServer($server)->putContent($file->source, $replacementSource);
                app(ApplicationStatusService::class)->recordSourceSave($server, $file->fileId, hash('sha256', $replacementSource));
                $plan = $this->bindings->saveFile($actor, $server, $file, $replacementSource);
                DB::table('bps_setup_failures')->where('server_id', $server->id)
                    ->where('file_id', $file->fileId)->where('attempt_id', $attempt)->delete();

                return $plan;
            });
        } catch (Throwable) {
            // No compensating database write or plaintext restore is required:
            // the separately committed intent survives this transaction's rollback.
            throw new DomainException('source_saved_plan_failed');
        }
    }
}
