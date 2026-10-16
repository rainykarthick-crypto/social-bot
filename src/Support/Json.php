<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * JSON helpers that tolerate the way real models and APIs wrap payloads
 * (code fences, prose around the object, single object instead of a list,
 * double-encoded strings).
 */
final class Json
{
    /**
     * Extract a JSON array from an arbitrary LLM/API response.
     *
     * @return array<int, mixed>|null
     */
    public static function extractArray(string $raw): ?array
    {
        $decoded = self::decode($raw);

        if (!is_array($decoded)) {
            return null;
        }

        // {"items":[...]} / {"results":[...]} / {"data":[...]} style wrappers.
        if (!array_is_list($decoded)) {
            foreach (['items', 'results', 'data', 'comments', 'leads', 'output'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                    return $decoded[$key];
                }
            }

            return [$decoded];
        }

        return $decoded;
    }

    /**
     * Extract a JSON object from an arbitrary LLM/API response.
     *
     * @return array<string, mixed>|null
     */
    public static function extractObject(string $raw): ?array
    {
        $decoded = self::decode($raw);

        if (!is_array($decoded)) {
            return null;
        }

        if (array_is_list($decoded)) {
            $first = $decoded[0] ?? null;

            return is_array($first) ? $first : null;
        }

        return $decoded;
    }

    /**
     * @return array<mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // 1. Straight decode.
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. Fenced code block.
        if (preg_match('/```(?:json|JSON)?\s*(.+?)```/s', $raw, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3. Slice from the first bracket to the matching last bracket.
        foreach ([['[', ']'], ['{', '}']] as [$open, $close]) {
            $start = strpos($raw, $open);
            $end = strrpos($raw, $close);

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        // 4. Double encoded (some upstreams stringify twice).
        $decoded = json_decode($raw, true);

        if (is_string($decoded)) {
            $twice = json_decode($decoded, true);

            if (is_array($twice)) {
                return $twice;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    public static function encode(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? 'null' : $encoded;
    }

    /**
     * Decode a DB JSON column that may be null / malformed.
     *
     * @return array<mixed>
     */
    public static function fromColumn(mixed $value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
