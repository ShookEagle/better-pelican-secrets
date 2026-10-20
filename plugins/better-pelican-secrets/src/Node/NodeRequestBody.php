<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use Illuminate\Http\Request;
use stdClass;
use Throwable;

final class NodeRequestBody
{
    public static function read(#[\SensitiveParameter] Request $request, int $maxBytes): stdClass
    {
        $stream = $request->getContent(true);
        $raw = stream_get_contents($stream, $maxBytes + 1);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($raw === false) {
            throw new NodeRequestError('protocol_invalid');
        }
        if (strlen($raw) > $maxBytes) {
            throw new NodeRequestError('limit_exceeded', 413);
        }
        try {
            $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new NodeRequestError('protocol_invalid');
        }
        if (!$value instanceof stdClass) {
            throw new NodeRequestError('protocol_invalid');
        }
        self::rejectDuplicates($raw);

        return $value;
    }

    public static function arrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    private static function rejectDuplicates(#[\SensitiveParameter] string $raw): void
    {
        $stack = [];
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '"') {
                $start = $i;
                for ($i++; $i < $length; $i++) {
                    if ($raw[$i] === '\\') {
                        $i++;
                    } elseif ($raw[$i] === '"') {
                        break;
                    }
                }
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object'] && $stack[$top]['key']) {
                    $key = json_decode(substr($raw, $start, $i - $start + 1), true, flags: JSON_THROW_ON_ERROR);
                    if (isset($stack[$top]['seen'][$key])) {
                        throw new NodeRequestError('protocol_invalid');
                    }
                    $stack[$top]['seen'][$key] = true;
                }
            } elseif ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'key' => true, 'seen' => []];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif (($char === ',' || $char === ':') && $stack) {
                $top = count($stack) - 1;
                if ($stack[$top]['object']) {
                    $stack[$top]['key'] = $char === ',';
                }
            }
        }
    }
}
