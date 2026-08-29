<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\helpers;

use craft\helpers\Json;

/**
 * Reading JSON columns back.
 *
 * This exists because of one trap that cost a whole afternoon. **Yii's query builder already encodes an
 * array on its way into a `json` column.** Handing it a string you encoded yourself stores the JSON of a
 * JSON string — `'"[\"news\"]"'` — and decoding that once yields the *string* `["news"]`, not an array.
 * Every getter guarded with `is_array()` then quietly returns nothing: no error, no warning, and a
 * notification that reaches everybody because its topic filter evaporated.
 *
 * The fix on the write side is to pass arrays and let the driver encode them. The fix on the read side is
 * this helper, which decodes until it has a container — so rows written by the earlier, wrong code are
 * read correctly too rather than being silently empty forever.
 */
final class Data
{
    /** How many times to unwrap. Two covers the double-encoded case; more would be hiding a bug. */
    private const MAX_DEPTH = 2;

    /**
     * @return array<mixed>
     */
    public static function toArray(mixed $value): array
    {
        for ($i = 0; $i <= self::MAX_DEPTH; $i++) {
            if (is_array($value)) {
                return $value;
            }

            if (!is_string($value) || trim($value) === '') {
                return [];
            }

            $decoded = Json::decodeIfJson($value);

            if ($decoded === $value) {
                // Not JSON at all, and not going to become JSON by trying again.
                return [];
            }

            $value = $decoded;
        }

        return [];
    }

    /**
     * A list of non-empty strings.
     *
     * @return string[]
     */
    public static function toStringList(mixed $value): array
    {
        $out = [];

        foreach (self::toArray($value) as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $string = trim((string)$item);

            if ($string !== '') {
                $out[] = $string;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A list of ints within a range, with zero permitted only when the range allows it.
     *
     * @return int[]
     */
    public static function toIntList(mixed $value, int $min, int $max): array
    {
        $out = [];

        foreach (self::toArray($value) as $item) {
            if (!is_numeric($item)) {
                continue;
            }

            $int = (int)$item;

            if ($int >= $min && $int <= $max) {
                $out[$int] = $int;
            }
        }

        ksort($out);

        return array_values($out);
    }
}
