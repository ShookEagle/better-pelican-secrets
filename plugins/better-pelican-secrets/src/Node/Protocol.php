<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use DomainException;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretVersionRef;

final class Protocol
{
    public const LIMITS = ['secret_bytes' => 65536, 'template_bytes' => 4194304, 'references_per_file' => 1000,
        'files_per_server' => 256, 'bundle_bytes' => 16777216, 'rendered_bytes' => 67108864];

    public const MODES = ['raw', 'json-string', 'yaml-string', 'dotenv'];

    public const FORMATS = ['text', 'json', 'yaml', 'dotenv'];

    public const ERRORS = ['protocol_invalid', 'protocol_unsupported', 'limit_exceeded', 'secret_missing',
        'template_invalid', 'manifest_mismatch', 'path_unsafe', 'node_incompatible', 'panel_unavailable',
        'revision_conflict', 'mount_failed', 'start_failed', 'decryption_failed', 'internal_error',
        'value_invalid', 'dotenv_unsupported'];

    public static function decodePlan(#[\SensitiveParameter] array $input): DesiredPlan
    {
        self::keys($input, ['protocol', 'server_uuid', 'revision', 'versions', 'files', 'startup', 'limits']);
        self::identity($input);
        if (!is_array($input['limits'])) {
            self::fail();
        }
        self::keys($input['limits'], array_keys(self::LIMITS));
        foreach (self::LIMITS as $name => $max) {
            if (!is_int($input['limits'][$name]) || $input['limits'][$name] < 1 || $input['limits'][$name] > $max) {
                self::fail();
            }
        }
        $limits = $input['limits'];
        $versions = self::versions($input['versions']);
        $versionIds = array_map(fn ($ref) => $ref->secretId, $versions);
        self::list($input['files']);
        self::list($input['startup']);
        if (count($input['files']) > $limits['files_per_server'] || count($input['startup']) > 4096) {
            self::fail();
        }
        $files = [];
        $used = [];
        $fileIds = [];
        $sources = [];
        $destinations = [];
        $names = [];
        foreach ($input['files'] as $file) {
            if (!is_array($file)) {
                self::fail();
            }
            self::keys($file, ['file_id', 'source', 'destination', 'format', 'default_mode', 'manifest']);
            self::uuid($file['file_id']);
            if (!self::path($file['source'], false) || !self::path($file['destination'], true)
                || !in_array($file['format'], self::FORMATS, true) || !in_array($file['default_mode'], self::MODES, true)) {
                self::fail();
            }
            if (isset($fileIds[$file['file_id']]) || isset($sources[$file['source']]) || isset($destinations[$file['destination']])) {
                self::fail();
            }
            $fileIds[$file['file_id']] = $sources[$file['source']] = $destinations[$file['destination']] = true;
            self::list($file['manifest']);
            $entries = [];
            $references = 0;
            foreach ($file['manifest'] as $entry) {
                if (!is_array($entry)) {
                    self::fail();
                }
                self::keys($entry, ['secret_id', 'name', 'mode', 'count']);
                self::uuid($entry['secret_id']);
                if (!is_string($entry['name']) || !preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $entry['name'])
                    || !in_array($entry['mode'], self::MODES, true) || !is_int($entry['count']) || $entry['count'] < 1
                    || !in_array($entry['secret_id'], $versionIds, true)) {
                    self::fail();
                }
                $key = $entry['secret_id'] . ':' . $entry['mode'];
                if (isset($entries[$key]) || (isset($names[$entry['name']]) && $names[$entry['name']] !== $entry['secret_id'])) {
                    self::fail();
                }
                $entries[$key] = true;
                $names[$entry['name']] = $entry['secret_id'];
                $used[$entry['secret_id']] = true;
                $references += $entry['count'];
                if ($references > $limits['references_per_file']) {
                    self::fail();
                }
            }
            $files[] = new FilePlan($file['file_id'], $file['source'], $file['destination'], $file['format'], $file['default_mode'], $file['manifest']);
        }
        $startup = [];
        $envs = [];
        foreach ($input['startup'] as $binding) {
            if (!is_array($binding)) {
                self::fail();
            }
            self::keys($binding, ['env', 'secret_id']);
            self::uuid($binding['secret_id']);
            if (!is_string($binding['env']) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $binding['env'])
                || isset($envs[strtoupper($binding['env'])]) || !in_array($binding['secret_id'], $versionIds, true)) {
                self::fail();
            }
            $envs[strtoupper($binding['env'])] = true;
            $used[$binding['secret_id']] = true;
            $startup[] = new StartupPlan($binding['env'], $binding['secret_id']);
        }
        if (count($used) !== count($versions)) {
            self::fail();
        }

        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > $limits['bundle_bytes']) {
            self::fail('limit_exceeded');
        }

        return new DesiredPlan(1, $input['server_uuid'], $input['revision'], $versions, $files, $startup, $limits);
    }

    public static function decodeBundle(#[\SensitiveParameter] array $input): RuntimeBundle
    {
        if (!array_key_exists('attempt_id', $input) || !array_key_exists('values', $input)) {
            self::fail();
        }
        $attempt = $input['attempt_id'];
        $rawValues = $input['values'];
        unset($input['attempt_id'], $input['values']);
        $plan = self::decodePlan($input);
        self::uuid($attempt);
        if (!is_array($rawValues) || count($rawValues) !== count($plan->versions)) {
            self::fail();
        }
        $values = [];
        $total = 0;
        foreach ($plan->versions as $ref) {
            $raw = $rawValues[$ref->secretId] ?? null;
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
                self::fail();
            }
            $total += strlen($raw);
            if (strlen($raw) > $plan->limits['secret_bytes'] || $total > $plan->limits['bundle_bytes']) {
                self::fail('limit_exceeded');
            }
            $values[$ref->secretId] = new SecretValue($raw);
        }
        if (strlen(json_encode([...$input, 'attempt_id' => $attempt, 'values' => $rawValues], JSON_THROW_ON_ERROR)) > $plan->limits['bundle_bytes']) {
            self::fail('limit_exceeded');
        }

        return new RuntimeBundle($plan, $attempt, $values);
    }

    public static function decodeReport(#[\SensitiveParameter] array $input): AppliedReport
    {
        $fields = ['protocol', 'server_uuid', 'kind', 'attempt_id', 'revision', 'source_digests', 'versions', 'error_code'];
        if (($input['kind'] ?? null) === 'source-dirty') {
            $fields[] = 'observation_sequence';
        }
        self::keys($input, $fields);
        $sequence = $input['observation_sequence'] ?? null;
        if ($input['kind'] === 'source-dirty' && (!is_string($sequence) || !preg_match('/\A[1-9][0-9]{0,19}\z/', $sequence)
            || (strlen($sequence) === 20 && strcmp($sequence, '18446744073709551615') > 0))) {
            self::fail();
        }
        self::identity($input);
        self::uuid($input['attempt_id']);
        if (!in_array($input['kind'], ['applied', 'failed', 'source-dirty'], true) || !is_array($input['source_digests'])
            || count($input['source_digests']) > self::LIMITS['files_per_server']) {
            self::fail();
        }
        foreach ($input['source_digests'] as $id => $digest) {
            self::uuid($id);
            if (!is_string($digest) || !preg_match('/\A[0-9a-f]{64}\z/', $digest)) {
                self::fail();
            }
        }
        if (($input['kind'] === 'failed' && !in_array($input['error_code'], self::ERRORS, true))
            || ($input['kind'] !== 'failed' && $input['error_code'] !== null)) {
            self::fail();
        }

        return new AppliedReport(1, $input['server_uuid'], $input['kind'], $input['attempt_id'], $input['revision'],
            $input['source_digests'], self::versions($input['versions']), $input['error_code'], $sequence);
    }

    private static function identity(array $input): void
    {
        if (!is_int($input['protocol'])) {
            self::fail();
        }
        if ($input['protocol'] !== 1) {
            self::fail('protocol_unsupported');
        }
        self::uuid($input['server_uuid']);
        $revision = $input['revision'];
        if (!is_string($revision) || !preg_match('/\A(0|[1-9][0-9]{0,19})\z/', $revision)
            || (strlen($revision) === 20 && strcmp($revision, '18446744073709551615') > 0)) {
            self::fail();
        }
    }

    private static function versions(mixed $input): array
    {
        self::list($input);
        if (count($input) > 256000) {
            self::fail();
        }
        $versions = [];
        $seen = [];
        foreach ($input as $ref) {
            if (!is_array($ref)) {
                self::fail();
            }
            self::keys($ref, ['secret_id', 'version']);
            self::uuid($ref['secret_id']);
            if (!is_int($ref['version']) || $ref['version'] < 1 || $ref['version'] > 2147483647 || isset($seen[$ref['secret_id']])) {
                self::fail();
            }
            $seen[$ref['secret_id']] = true;
            $versions[] = new SecretVersionRef($ref['secret_id'], $ref['version']);
        }

        return $versions;
    }

    private static function keys(array $input, array $keys): void
    {
        if (count($input) !== count($keys) || array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) {
            self::fail();
        }
    }

    private static function list(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::fail();
        }
    }

    private static function uuid(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value)) {
            self::fail();
        }
    }

    private static function path(mixed $value, bool $absolute): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $value) || str_starts_with($value, '/') !== $absolute) {
            return false;
        }
        foreach (explode('/', $absolute ? substr($value, 1) : $value) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function fail(string $code = 'protocol_invalid'): never
    {
        throw new DomainException($code);
    }
}
