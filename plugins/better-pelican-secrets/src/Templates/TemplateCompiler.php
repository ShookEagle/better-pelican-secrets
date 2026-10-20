<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

use Dotenv\Dotenv;
use ShookEagle\BetterPelicanSecrets\Node\Protocol;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class TemplateCompiler
{
    private array $limits;

    public function __construct(array $limits = [])
    {
        if (array_diff(array_keys($limits), array_keys(Protocol::LIMITS))) {
            throw new TemplateError('limit_exceeded');
        }
        $this->limits = [...Protocol::LIMITS, ...$limits];
        foreach ($this->limits as $key => $value) {
            if (!is_int($value) || $value < 1 || $value > Protocol::LIMITS[$key]) {
                throw new TemplateError('limit_exceeded');
            }
        }
    }

    public function compile(TemplateDocument $document): CompiledTemplate
    {
        $source = $document->source;
        if (strlen($source) > $this->limits['template_bytes']) {
            throw new TemplateError('limit_exceeded');
        }
        if (!$this->text($source) || !in_array($document->format, Protocol::FORMATS, true)
            || !in_array($document->defaultMode, Protocol::MODES, true)
            || ($document->format !== 'text' && str_starts_with($source, "\xef\xbb\xbf"))) {
            throw new TemplateError('template_invalid');
        }
        $approved = $this->manifest($document->manifest);
        $observed = [];
        $parts = [];
        $cursor = 0;
        $references = 0;
        while (preg_match('/\{\{!?secret/', $source, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            $start = $match[0][1];
            if ($start > $cursor) {
                $this->appendLiteral($parts, $cursor, $start - $cursor, substr($source, $cursor, $start - $cursor));
            }
            $end = strpos($source, '}}', $start);
            if ($end === false || $end - $start > 128) {
                throw new TemplateError('template_invalid');
            }
            $length = $end + 2 - $start;
            $token = substr($source, $start, $length);
            if (preg_match('/\A\{\{!secret:([A-Z][A-Z0-9_]{0,63})\}\}\z/', $token, $literal)) {
                $this->appendLiteral($parts, $start, $length, '{{secret:' . $literal[1] . '}}');
            } else {
                if (!preg_match('/\A\{\{secret:([A-Z][A-Z0-9_]{0,63})(?:\|(raw|json-string|yaml-string|dotenv))?\}\}\z/', $token, $matches)) {
                    throw new TemplateError('template_invalid');
                }
                if (++$references > $this->limits['references_per_file']) {
                    throw new TemplateError('limit_exceeded');
                }
                $mode = $matches[2] ?? $document->defaultMode;
                $key = $matches[1] . ':' . $mode;
                if (!isset($approved[$key])) {
                    throw new TemplateError('manifest_mismatch');
                }
                $observed[$key] = ($observed[$key] ?? 0) + 1;
                $parts[] = ['start' => $start, 'length' => $length, 'secret_id' => $approved[$key]['secret_id'], 'mode' => $mode];
            }
            $cursor = $end + 2;
        }
        if ($cursor < strlen($source)) {
            $this->appendLiteral($parts, $cursor, strlen($source) - $cursor, substr($source, $cursor));
        }
        foreach ($approved as $key => $entry) {
            if (($observed[$key] ?? 0) !== $entry['count']) {
                throw new TemplateError('manifest_mismatch');
            }
        }

        return new CompiledTemplate($document->format, $parts, $document->manifest);
    }

    public function render(CompiledTemplate $template, #[\SensitiveParameter] array $valuesBySecretId, int $remainingBytes = 67108864): string
    {
        $budget = min($remainingBytes, $this->limits['rendered_bytes']);
        if ($budget < 0) {
            throw new TemplateError('limit_exceeded');
        }
        $output = '';
        foreach ($template->parts as $part) {
            if (array_key_exists('literal', $part)) {
                $chunk = $part['literal'];
            } else {
                $value = $valuesBySecretId[$part['secret_id']] ?? null;
                if ($value instanceof SecretValue) {
                    $value = $value->bytes();
                }
                if (!is_string($value)) {
                    throw new TemplateError('secret_missing');
                }
                if (!$this->text($value)) {
                    throw new TemplateError('value_invalid');
                }
                if (strlen($value) > $this->limits['secret_bytes']) {
                    throw new TemplateError('limit_exceeded');
                }
                $chunk = $this->encode($value, $part['mode']);
            }
            if (strlen($chunk) > $budget - strlen($output)) {
                throw new TemplateError('limit_exceeded');
            }
            $output .= $chunk;
        }
        $this->validateOutput($output, $template->format);

        return $output;
    }

    private function appendLiteral(array &$parts, int $start, int $length, string $literal): void
    {
        $last = count($parts) - 1;
        if ($last >= 0 && array_key_exists('literal', $parts[$last])) {
            $parts[$last]['literal'] .= $literal;
            $parts[$last]['length'] += $length;
        } else {
            $parts[] = ['start' => $start, 'length' => $length, 'literal' => $literal];
        }
    }

    private function manifest(array $entries): array
    {
        if (!array_is_list($entries)) {
            throw new TemplateError('manifest_mismatch');
        }
        $approved = [];
        $ids = [];
        $names = [];
        $identityNames = [];
        $references = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 4 || array_diff(array_keys($entry), ['secret_id', 'name', 'mode', 'count'])
                || !is_string($entry['name'] ?? null) || !preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $entry['name'])
                || !is_string($entry['secret_id'] ?? null) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $entry['secret_id'])
                || !in_array($entry['mode'] ?? null, Protocol::MODES, true) || !is_int($entry['count'] ?? null) || $entry['count'] < 1) {
                throw new TemplateError('manifest_mismatch');
            }
            $references += $entry['count'];
            if ($references > $this->limits['references_per_file']) {
                throw new TemplateError('limit_exceeded');
            }
            $key = $entry['name'] . ':' . $entry['mode'];
            $identityMode = $entry['secret_id'] . ':' . $entry['mode'];
            if (isset($approved[$key]) || isset($ids[$identityMode]) || (isset($names[$entry['name']]) && $names[$entry['name']] !== $entry['secret_id']) || (isset($identityNames[$entry['secret_id']]) && $identityNames[$entry['secret_id']] !== $entry['name'])) {
                throw new TemplateError('manifest_mismatch');
            }
            $approved[$key] = $entry;
            $ids[$identityMode] = true;
            $names[$entry['name']] = $entry['secret_id'];
            $identityNames[$entry['secret_id']] = $entry['name'];
        }

        return $approved;
    }

    private function text(#[\SensitiveParameter] string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0");
    }

    private function encode(#[\SensitiveParameter] string $value, string $mode): string
    {
        return match ($mode) {
            'raw' => $value,
            'json-string' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'yaml-string' => $this->yamlValue($value),
            'dotenv' => $this->dotenvValue($value),
            default => throw new TemplateError('template_invalid'),
        };
    }

    private function yamlValue(#[\SensitiveParameter] string $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return preg_replace_callback('/[\x{007f}-\x{009f}\x{fffe}\x{ffff}]/u',
            fn ($match) => sprintf('\u%04x', mb_ord($match[0], 'UTF-8')), $encoded);
    }

    private function dotenvValue(#[\SensitiveParameter] string $value): string
    {
        if (preg_match('/[\x00-\x08\x0e-\x1f\x7f]/', $value)) {
            throw new TemplateError('dotenv_unsupported');
        }

        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\r" => '\r', "\n" => '\n', "\t" => '\t', "\f" => '\f', "\v" => '\v']) . '"';
    }

    private function validateOutput(#[\SensitiveParameter] string $output, string $format): void
    {
        try {
            if ($format === 'json') {
                json_decode($output, true, 64, JSON_THROW_ON_ERROR);
            } elseif ($format === 'yaml') {
                Yaml::parse($output, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS, 64, 0);
            } elseif ($format === 'dotenv') {
                $this->validateDotenv($output);
                Dotenv::parse($output);
            } elseif ($format !== 'text') {
                throw new TemplateError('template_invalid');
            }
        } catch (TemplateError $error) {
            throw $error;
        } catch (Throwable) {
            throw new TemplateError('template_invalid');
        }
    }

    /** Single physical line assignments; quoted escapes carry multiline values losslessly. */
    private function validateDotenv(#[\SensitiveParameter] string $output): void
    {
        $normalized = str_replace("\r\n", "\n", $output);
        if (str_contains($normalized, "\r") || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $normalized)) {
            throw new TemplateError('dotenv_unsupported');
        }
        foreach (explode("\n", $normalized) as $line) {
            $line = ltrim($line, " \t");
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/\A(?:export[ \t]+)?[A-Za-z0-9_.]+[ \t]*=[ \t]*(.*)\z/', $line, $matches)) {
                throw new TemplateError('dotenv_unsupported');
            }
            $value = $matches[1];
            if ($value === '' || $value[0] === '#') {
                continue;
            }
            $quote = $value[0];
            if ($quote === '"' || $quote === "'") {
                $closed = false;
                for ($i = 1; $i < strlen($value); $i++) {
                    if ($quote === '"' && $value[$i] === '\\') {
                        if (++$i >= strlen($value) || !in_array($value[$i], ['"', '\\', '$', 'n', 'r', 't', 'f', 'v'], true)) {
                            throw new TemplateError('dotenv_unsupported');
                        }
                    } elseif ($value[$i] === $quote) {
                        $tail = ltrim(substr($value, $i + 1), " \t");
                        if ($tail !== '' && $tail[0] !== '#') {
                            throw new TemplateError('dotenv_unsupported');
                        }
                        $closed = true;
                        break;
                    }
                }
                if (!$closed) {
                    throw new TemplateError('dotenv_unsupported');
                }
            } else {
                if (!preg_match('/\A[^\s#\'"\\\\]*(?:[ \t]*(?:#.*)?)\z/u', $value)) {
                    throw new TemplateError('dotenv_unsupported');
                }
            }
        }
    }
}
