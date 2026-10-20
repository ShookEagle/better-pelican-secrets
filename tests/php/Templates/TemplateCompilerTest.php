<?php

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateCompiler;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateDocument;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateError;
use Symfony\Component\Yaml\Yaml;

final class TemplateCompilerTest extends TestCase
{
    private const ID = '22222222-2222-4222-8222-222222222222';

    private function manifest(string $mode = 'raw', int $count = 1): array
    {
        return [['secret_id' => self::ID, 'name' => 'KEY', 'mode' => $mode, 'count' => $count]];
    }

    public function test_shared_rendering_vectors(): void
    {
        $path = getenv('BPS_PROTOCOL_FIXTURES');
        $this->assertNotFalse($path);
        $fixtures = json_decode(file_get_contents($path . '/rendering.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['vectors'] as $vector) {
            $compiler = new TemplateCompiler($vector['limits'] ?? []);
            try {
                $source = isset($vector['source_base64']) ? base64_decode($vector['source_base64'], true) : $vector['source'];
                $values = $vector['values'];
                foreach ($vector['values_base64'] ?? [] as $id => $encoded) {
                    $values[$id] = base64_decode($encoded, true);
                }
                $compiled = $compiler->compile(new TemplateDocument($source, $vector['format'], $vector['default_mode'], $vector['manifest']));
                $output = $compiler->render($compiled, $values);
                $this->assertArrayNotHasKey('error_code', $vector, $vector['name']);
                $this->assertSame($vector['expected_output'], $output, $vector['name']);
            } catch (TemplateError $exception) {
                $this->assertSame($vector['error_code'] ?? null, $exception->getMessage(), $vector['name']);
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
            }
        }
    }

    public function test_structured_strings_roundtrip_without_interpolation(): void
    {
        $value = "quote\" slash\\ dollar\$ line\n雪";
        foreach ([
            ['json', 'json-string', '{"apiKey":{{secret:KEY}}}'],
            ['yaml', 'yaml-string', "apiKey: {{secret:KEY}}\n"],
            ['dotenv', 'dotenv', "API_KEY={{secret:KEY}}\n"],
        ] as [$format, $mode, $source]) {
            $compiler = new TemplateCompiler();
            $compiled = $compiler->compile(new TemplateDocument($source, $format, $mode, $this->manifest($mode)));
            foreach ([$value, '$' . '{OTHER}', "\r\n\t\f\v", "\u{0085}\u{2028}\u{2029}", ''] as $testValue) {
                $output = $compiler->render($compiled, [self::ID => $testValue]);
                $decoded = match ($format) {
                    'json' => json_decode($output, true, flags: JSON_THROW_ON_ERROR),
                    'yaml' => Yaml::parse($output),
                    'dotenv' => Dotenv::parse($output),
                };
                $this->assertSame($testValue, $decoded[$format === 'dotenv' ? 'API_KEY' : 'apiKey'], $format);
            }
        }
    }

    public function test_line_endings_and_encoding(): void
    {
        $compiler = new TemplateCompiler();
        $source = "\xef\xbb\xbfalpha\r\n{{secret:KEY}}\r\nlast";
        $compiled = $compiler->compile(new TemplateDocument($source, 'text', 'raw', $this->manifest()));
        $this->assertSame("\xef\xbb\xbfalpha\r\nfixture-secret\r\nlast", $compiler->render($compiled, [self::ID => 'fixture-secret']));
        foreach ([["\xff", 'text'], ["bad\0text", 'text'], ["\xef\xbb\xbf{}", 'json'], ["\xef\xbb\xbfKEY=x", 'dotenv'], ["\xef\xbb\xbfkey: x", 'yaml']] as [$bad, $format]) {
            try {
                $compiler->compile(new TemplateDocument($bad, $format, 'raw', []));
                $this->fail('Invalid source encoding accepted');
            } catch (TemplateError $exception) {
                $this->assertSame('template_invalid', $exception->getMessage());
            }
        }
    }

    public function test_manifest_changes_and_malformed_tokens_fail(): void
    {
        foreach (['{{secret:OTHER}}', '{{secret:KEY}} {{secret:KEY}}', '', '{{secret:KEY|json-string}}'] as $source) {
            try {
                (new TemplateCompiler())->compile(new TemplateDocument($source, 'text', 'raw', $this->manifest()));
                $this->fail('Changed binding manifest accepted');
            } catch (TemplateError $exception) {
                $this->assertSame('manifest_mismatch', $exception->getMessage());
            }
        }
        foreach (['{{secret KEY}}', '{{secret:lower}}', '{{secret:KEY|eval}}', '{{secret:KEY', '{{secret}}'] as $source) {
            try {
                (new TemplateCompiler())->compile(new TemplateDocument($source, 'text', 'raw', $this->manifest()));
                $this->fail('Malformed token accepted');
            } catch (TemplateError $exception) {
                $this->assertSame('template_invalid', $exception->getMessage());
            }
        }
    }

    public function test_repeated_tokens_hit_expanded_budget(): void
    {
        $compiler = new TemplateCompiler(['rendered_bytes' => 1024]);
        $template = $compiler->compile(new TemplateDocument(str_repeat('{{secret:KEY}}', 1000), 'text', 'raw', $this->manifest('raw', 1000)));
        try {
            $compiler->render($template, [self::ID => str_repeat('x', 65536)]);
            $this->fail('Expanded output exceeded its budget');
        } catch (TemplateError $exception) {
            $this->assertSame('limit_exceeded', $exception->getMessage());
        }
        try {
            $compiler->compile(new TemplateDocument(str_repeat('{{secret:KEY}}', 1001), 'text', 'raw', $this->manifest('raw', 1001)));
            $this->fail('1001st reference accepted');
        } catch (TemplateError $exception) {
            $this->assertSame('limit_exceeded', $exception->getMessage());
        }
        $this->assertSame(1000, strlen($compiler->render($template, [self::ID => 'x'])));
    }

    public function test_render_failure_is_redacted_and_has_no_fallback(): void
    {
        $compiler = new TemplateCompiler();
        $compiled = $compiler->compile(new TemplateDocument('{"key":{{secret:KEY}}}', 'json', 'raw', $this->manifest()));
        foreach ([[self::ID => 'fixture-secret'], [], [self::ID => "\0fixture-secret"], [self::ID => "\xff"]] as $values) {
            try {
                $compiler->render($compiled, $values);
                $this->fail('Invalid value/render accepted');
            } catch (TemplateError $exception) {
                $this->assertStringNotContainsString('fixture-secret', (string) $exception);
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function test_manifest_name_and_id_are_one_to_one_across_modes(): void
    {
        $manifest = [
            ['secret_id' => self::ID, 'name' => 'KEY', 'mode' => 'raw', 'count' => 1],
            ['secret_id' => '55555555-5555-4555-8555-555555555555', 'name' => 'KEY', 'mode' => 'json-string', 'count' => 1],
        ];
        $this->expectException(TemplateError::class);
        (new TemplateCompiler())->compile(new TemplateDocument('{{secret:KEY}} {{secret:KEY|json-string}}', 'text', 'raw', $manifest));
    }

    public function test_literal_escapes_do_not_create_one_part_per_token(): void
    {
        $compiler = new TemplateCompiler();
        $compiled = $compiler->compile(new TemplateDocument(str_repeat('{{!secret:KEY}} ', 2000), 'text', 'raw', []));
        $this->assertLessThanOrEqual(2, count($compiled->parts));
        $this->assertSame(str_repeat('{{secret:KEY}} ', 2000), $compiler->render($compiled, []));
    }

    public function test_source_secret_and_expansion_byte_boundaries(): void
    {
        $compiler = new TemplateCompiler();
        $source = str_repeat('a', 4194304);
        $this->assertSame($source, $compiler->render($compiler->compile(new TemplateDocument($source, 'text', 'raw', [])), []));
        try {
            $compiler->compile(new TemplateDocument($source . 'a', 'text', 'raw', []));
            $this->fail('Oversized template accepted');
        } catch (TemplateError $exception) {
            $this->assertSame('limit_exceeded', $exception->getMessage());
        }
        $template = $compiler->compile(new TemplateDocument('{{secret:KEY}}', 'text', 'raw', $this->manifest()));
        $this->assertSame(65536, strlen($compiler->render($template, [self::ID => str_repeat('é', 32768)])));
        try {
            $compiler->render($template, [self::ID => str_repeat('é', 32768) . 'a']);
            $this->fail('Oversized value accepted');
        } catch (TemplateError $exception) {
            $this->assertSame('limit_exceeded', $exception->getMessage());
        }
        $small = new TemplateCompiler(['rendered_bytes' => 10]);
        $quoted = $small->compile(new TemplateDocument('{{secret:KEY}}', 'text', 'json-string', $this->manifest('json-string')));
        try {
            $small->render($quoted, [self::ID => "\x01\x01"]);
            $this->fail('Escaping expansion exceeded output budget');
        } catch (TemplateError $exception) {
            $this->assertSame('limit_exceeded', $exception->getMessage());
        }
    }

    public function test_unsupported_dotenv_multiline_and_yaml_aliases_fail(): void
    {
        foreach ([
            ['dotenv', "API_KEY=\"first\nsecond\"\n", 'dotenv_unsupported'],
            ['yaml', "base: &x {one: 1}\ncopy: *x\n", 'template_invalid'],
        ] as [$format, $source, $code]) {
            $compiler = new TemplateCompiler();
            try {
                $compiler->render($compiler->compile(new TemplateDocument($source, $format, 'raw', [])), []);
                $this->fail('Unsupported structured construct accepted');
            } catch (TemplateError $exception) {
                $this->assertSame($code, $exception->getMessage());
            }
        }
    }
}
