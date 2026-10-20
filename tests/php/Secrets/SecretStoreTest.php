<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Server;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Secrets\AuditRecorder;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretVersionRef;

final class SecretStoreTest extends PluginTestCase
{
    private function store(): SecretStore
    {
        return $this->app->make(SecretStore::class);
    }

    private function create(string $name = 'API_KEY', string $value = 'fixture-secret')
    {
        return $this->store()->create($this->server, $name, 'Non-secret setup notes', new SecretValue($value), $this->actor);
    }

    public function test_ciphertext_is_not_value(): void
    {
        $metadata = $this->create();
        $row = DB::table('bps_secret_versions')->where('secret_id', $metadata->secretId)->first();
        $this->assertStringNotContainsString('fixture-secret', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertNotEmpty($row->encrypted_value);
        $this->assertSame('fixture-secret', $this->store()->reveal(new SecretVersionRef($metadata->secretId, 1))->bytes());
        $this->assertSame($this->server->id, $metadata->serverId);
        $this->assertSame('Non-secret setup notes', $metadata->description);
        $this->assertSame(1, $metadata->currentVersion);
    }

    public function test_versions_are_immutable(): void
    {
        $first = $this->create();
        $newVersion = $this->store()->replace($first->secretId, new SecretValue('second-secret'), $this->actor);
        $this->assertSame(2, $newVersion->version);
        $this->assertSame(2, $this->store()->metadata($first->secretId)->currentVersion);
        $this->assertSame('fixture-secret', $this->store()->reveal(new SecretVersionRef($first->secretId, 1))->bytes());
        $this->assertSame('second-secret', $this->store()->reveal($newVersion)->bytes());
        $row = SecretVersion::query()->where('secret_id', $first->secretId)->where('version', 1)->firstOrFail();
        $row->encrypted_value = 'overwritten';
        $this->expectException(DomainException::class);
        $row->save();
    }

    public function test_empty_is_distinct_from_unchanged(): void
    {
        $metadata = $this->create();
        $this->assertSame(1, $this->store()->metadata($metadata->secretId)->currentVersion);
        $empty = $this->store()->replace($metadata->secretId, new SecretValue(''), $this->actor);
        $this->assertSame(2, $empty->version);
        $this->assertSame('', $this->store()->reveal($empty)->bytes());
        $createdEmpty = $this->create('EMPTY_VALUE', '');
        $this->assertSame('', $this->store()->reveal(new SecretVersionRef($createdEmpty->secretId, 1))->bytes());
    }

    public function test_audit_and_serialization_exclude_values(): void
    {
        $metadata = $this->create();
        $value = $this->store()->reveal(new SecretVersionRef($metadata->secretId, 1));
        ob_start();
        var_dump($value);
        $debug = ob_get_clean();
        $representations = [
            json_encode($metadata), json_encode($value), (string) $value, serialize($value), $debug, var_export($value, true),
            Secret::query()->findOrFail($metadata->secretId)->toJson(),
            SecretVersion::query()->firstOrFail()->toJson(),
            json_encode(DB::table('bps_secret_audit_events')->get()),
        ];
        foreach ($representations as $representation) {
            $this->assertStringNotContainsString('fixture-secret', $representation);
        }
        $this->assertArrayNotHasKey('encrypted_value', SecretVersion::query()->firstOrFail()->toArray());
        $event = DB::table('bps_secret_audit_events')->where('operation', 'secret.create')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('Non-secret setup notes', json_encode($event));
    }

    public function test_name_grammar_and_server_uniqueness(): void
    {
        foreach (['a', '_KEY', '1KEY', "KEY\n", 'KEY-DASH', str_repeat('A', 65), ''] as $name) {
            try {
                $this->create($name);
                $this->fail('Invalid name accepted');
            } catch (DomainException $exception) {
                $this->assertSame('Invalid secret name.', $exception->getMessage());
            }
        }
        $this->create('A' . str_repeat('_', 63));
        $this->create();
        try {
            $this->create();
            $this->fail('Duplicate server/name accepted');
        } catch (DomainException) {
            $this->assertSame(2, Secret::query()->count());
        }
        $other = Server::factory()->create(['owner_id' => $this->actor->id]);
        $this->store()->create($other, 'API_KEY', '', new SecretValue('other'), $this->actor);
        $this->assertSame(3, Secret::query()->count());
    }

    public function test_names_are_immutable(): void
    {
        $metadata = $this->create();
        $secret = Secret::query()->findOrFail($metadata->secretId);
        $secret->name = 'RENAMED';
        $this->expectException(DomainException::class);
        $secret->save();
    }

    public function test_secret_value_boundaries(): void
    {
        $boundary = str_repeat('é', 32768);
        $this->assertSame(65536, strlen((new SecretValue($boundary))->bytes()));
        $metadata = $this->create('BOUNDARY', $boundary);
        $this->assertSame($boundary, $this->store()->reveal(new SecretVersionRef($metadata->secretId, 1))->bytes());
        foreach ([$boundary . 'a', "\xff", "fixture-secret\0"] as $invalid) {
            try {
                new SecretValue($invalid);
                $this->fail('Invalid secret value accepted');
            } catch (DomainException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            }
        }
    }

    public function test_encryption_failure_rolls_back_without_leaking(): void
    {
        $this->app->instance(EncrypterContract::class, new class(str_repeat('a', 32), 'AES-256-CBC') extends Encrypter
        {
            public function encrypt(#[SensitiveParameter] $value, $serialize = true)
            {
                throw new RuntimeException('fixture-secret');
            }
        });
        try {
            $this->create();
            $this->fail('Failed encryption accepted');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(0, DB::table('bps_secrets')->count());
        $this->assertSame(0, DB::table('bps_secret_versions')->count());
        $this->assertSame(0, DB::table('bps_secret_audit_events')->count());
    }

    public function test_corrupt_ciphertext_has_no_fallback(): void
    {
        $metadata = $this->create();
        DB::table('bps_secret_versions')->where('secret_id', $metadata->secretId)->update(['encrypted_value' => 'fixture-secret']);
        try {
            $this->store()->reveal(new SecretVersionRef($metadata->secretId, 1));
            $this->fail('Corrupt value accepted');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_generated_values_have_32_random_bytes(): void
    {
        $one = SecretValue::generate()->bytes();
        $two = SecretValue::generate()->bytes();
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $one);
        $this->assertNotSame($one, $two);
        $this->assertSame(32, strlen(base64_decode(strtr($one, '-_', '+/') . '=', true)));
    }

    public function test_audit_rejects_unapproved_metadata(): void
    {
        foreach ([['value' => 'fixture-secret'], ['version' => 'fixture-secret'], ['secret_id' => 'fixture-secret']] as $metadata) {
            try {
                $this->app->make(AuditRecorder::class)->record($this->actor, 'secret.create', $metadata);
                $this->fail('Unapproved audit metadata accepted');
            } catch (DomainException $exception) {
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            }
        }
        $this->assertSame(0, DB::table('bps_secret_audit_events')->count());
    }

    public function test_delete_removes_versions_and_keeps_metadata_audit(): void
    {
        $metadata = $this->create();
        $this->store()->replace($metadata->secretId, new SecretValue('new-value'), $this->actor);
        $this->store()->delete($metadata->secretId, $this->actor);
        $this->assertSame(0, DB::table('bps_secrets')->count());
        $this->assertSame(0, DB::table('bps_secret_versions')->count());
        $this->assertSame(3, DB::table('bps_secret_audit_events')->count());
        $this->assertDatabaseAuditDeletion($metadata->secretId);
    }

    public function test_description_is_non_secret_metadata_and_validated(): void
    {
        $metadata = $this->store()->create($this->server, 'NOTES', 'Visible setup notes', new SecretValue('fixture-secret'), $this->actor);
        $this->assertStringContainsString('Visible setup notes', json_encode($metadata));
        foreach (["bad\0description", "\xff", str_repeat('a', 4097)] as $description) {
            try {
                $this->store()->create($this->server, 'INVALID_NOTES', $description, new SecretValue('fixture-secret'), $this->actor);
                $this->fail('Invalid description accepted');
            } catch (DomainException $exception) {
                $this->assertSame('Invalid secret description.', $exception->getMessage());
            }
        }
    }

    public function test_version_reference_rejects_invalid_identity(): void
    {
        foreach ([['not-an-id', 1], [Str::uuid()->toString(), 0]] as [$id, $version]) {
            try {
                new SecretVersionRef($id, $version);
                $this->fail('Invalid version reference accepted');
            } catch (DomainException $exception) {
                $this->assertSame('Invalid secret version reference.', $exception->getMessage());
            }
        }
    }

    private function assertDatabaseAuditDeletion(string $id): void
    {
        $event = DB::table('bps_secret_audit_events')->where('operation', 'secret.delete')->first();
        $this->assertSame($id, json_decode($event->metadata, true)['secret_id']);
    }
}
