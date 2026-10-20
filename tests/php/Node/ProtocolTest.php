<?php

use PHPUnit\Framework\TestCase;
use ShookEagle\BetterPelicanSecrets\Node\Protocol;

final class ProtocolTest extends TestCase
{
    private function fixtures(): array
    {
        $path = getenv('BPS_PROTOCOL_FIXTURES');
        $this->assertNotFalse($path);

        return json_decode(file_get_contents($path . '/protocol.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_revision_roundtrip_above_js_safe_integer(): void
    {
        $wire = $this->fixtures()['plan'];
        $decoded = Protocol::decodePlan($wire);
        $this->assertSame('9007199254740993', $decoded->revision);
        $this->assertSame($wire, $decoded->toWire());
        $this->assertSame($wire, json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true));
    }

    public function test_runtime_values_never_enter_plan_or_report(): void
    {
        $fixtures = $this->fixtures();
        $bundle = Protocol::decodeBundle($fixtures['bundle']);
        $plan = Protocol::decodePlan($fixtures['plan']);
        $report = Protocol::decodeReport($fixtures['report']);
        ob_start();
        var_dump($bundle);
        $debug = ob_get_clean();
        foreach ([json_encode($plan), json_encode($report), json_encode($bundle), (string) $bundle, $debug, var_export($bundle, true), serialize($bundle)] as $output) {
            $this->assertStringNotContainsString('fixture-secret', $output);
        }
        // This explicit export is used only by the authenticated node controller.
        $this->assertSame($fixtures['bundle'], $bundle->toWire());
    }

    public function test_strict_shared_invalid_plan_vectors(): void
    {
        foreach ($this->fixtures()['invalid_plans'] as $fixture) {
            try {
                Protocol::decodePlan($fixture['input']);
                $this->fail('Accepted invalid protocol vector ' . $fixture['name']);
            } catch (DomainException $exception) {
                $this->assertSame($fixture['error_code'], $exception->getMessage());
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            }
        }
    }

    public function test_bundle_rejects_extra_missing_invalid_or_oversized_values(): void
    {
        $fixture = $this->fixtures()['bundle'];
        $variants = [];
        $extra = $fixture;
        $extra['values']['55555555-5555-4555-8555-555555555555'] = 'fixture-secret';
        $variants[] = $extra;
        $missing = $fixture;
        $missing['values'] = [];
        $variants[] = $missing;
        $bad = $fixture;
        $bad['values'][array_key_first($bad['values'])] = str_repeat('x', 65537);
        $variants[] = $bad;
        foreach ($variants as $wire) {
            try {
                Protocol::decodeBundle($wire);
                $this->fail('Accepted invalid runtime bundle');
            } catch (DomainException $exception) {
                $this->assertContains($exception->getMessage(), ['protocol_invalid', 'limit_exceeded']);
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            }
        }
    }

    public function test_report_cannot_contain_arbitrary_errors_or_rendered_data(): void
    {
        foreach (['error_code', 'unexpected'] as $key) {
            $wire = $this->fixtures()['report'];
            $wire[$key] = 'fixture-secret';
            try {
                Protocol::decodeReport($wire);
                $this->fail('Accepted value-bearing report');
            } catch (DomainException $exception) {
                $this->assertSame('protocol_invalid', $exception->getMessage());
            }
        }
    }
}
