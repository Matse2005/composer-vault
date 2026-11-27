<?php

namespace App\Vault\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Prepares the changes of an audit entry: secret values are masked, sensitive keys are removed, and every value is
 * reduced to a string, integer, boolean, null or array, so the entry can be hashed.
 */
final class AuditChanges
{
    /**
     * Keys whose value is replaced by "changed". Notes are free text written by an admin and could hold names, which
     * must not enter a hash chain that can never be erased.
     */
    private const MASKED_KEYS = ['secret', 'password', 'plain_token', 'notes'];

    /** Keys that are removed. */
    private const REMOVED_KEYS = [
        'secret_ciphertext',
        'data_key_ciphertext',
        'key_version',
        'token_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'credential',
    ];

    /**
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException When a value is an object that cannot be reduced.
     */
    public static function sanitize(array $changes): array
    {
        $sanitized = [];

        foreach ($changes as $key => $value) {
            $name = is_string($key) ? strtolower($key) : null;

            if ($name !== null && in_array($name, self::REMOVED_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = $name !== null && in_array($name, self::MASKED_KEYS, true)
                ? 'changed'
                : self::sanitizeValue($value);
        }

        return $sanitized;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => self::sanitize($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s\Z'),
            is_float($value) => (string) $value,
            is_string($value), is_int($value), is_bool($value), $value === null => $value,
            default => throw new InvalidArgumentException('An audit value must be a scalar, an array, an enum or a date.'),
        };
    }
}
