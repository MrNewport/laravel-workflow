<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Support;

use InvalidArgumentException;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Copy finite JSON data without retaining PHP references or changing object-key order. */
    public static function detach(array $value): array
    {
        return self::normalize($value, 0, false);
    }

    /** Old receipts did not distinguish a reordered numeric object from a multi-item list. */
    public static function needsLegacyReview(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (array_is_list($value) && count($value) > 1) {
            return true;
        }
        foreach ($value as $item) {
            if (self::needsLegacyReview($item)) {
                return true;
            }
        }

        return false;
    }

    private static function numericObjectBecomesList(array $keys): bool
    {
        sort($keys, SORT_NUMERIC);

        return $keys === range(0, count($keys) - 1);
    }

    private static function normalize(mixed $value, int $depth = 0, bool $canonical = true): mixed
    {
        if ($depth > 512) {
            throw new InvalidArgumentException('Workflow JSON nesting exceeds 512 levels or contains recursive arrays.');
        }
        if (is_array($value)) {
            $keys = array_keys($value);
            $isList = array_is_list($value);
            if (! $canonical && ! $isList && self::numericObjectBecomesList($keys)) {
                throw new InvalidArgumentException('Numeric-key JSON objects with contiguous list keys cannot be stored portably. Use named object keys or a list.');
            }
            if ($canonical && ! array_is_list($value)) {
                sort($keys, SORT_STRING);
            }
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = self::normalize($value[$key], $depth + 1, $canonical);
            }

            return $canonical && ! $isList ? (object) $result : $result;
        }

        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Workflow values must be finite JSON data.');
        }

        return $value;
    }
}
