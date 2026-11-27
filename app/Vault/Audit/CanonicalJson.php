<?php

namespace App\Vault\Audit;

use InvalidArgumentException;
use JsonException;

/**
 * Encodes audit data in one fixed form, so the same data always gives the same hash input: associative arrays are
 * sorted by key (lists keep their order), slashes and unicode are not escaped.
 */
final class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $data  Only strings, integers, booleans, null and arrays of these.
     *
     * @throws InvalidArgumentException When a value has another type, for example a float or an object.
     * @throws JsonException When a string is not valid UTF-8.
     */
    public static function encode(array $data): string
    {
        return json_encode(self::normalize($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            } elseif (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('Audit data may only hold strings, integers, booleans, null and arrays of these.');
            }
        }

        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
