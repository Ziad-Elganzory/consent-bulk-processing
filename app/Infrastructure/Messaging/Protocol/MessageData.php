<?php

namespace App\Infrastructure\Messaging\Protocol;

use InvalidArgumentException;

/**
 * Validation helpers for reading and constructing message data.
 */
final class MessageData
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("The [{$key}] field must be a string.");
        }

        self::assertNonEmptyString($value, $key);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function requiredInt(array $data, string $key, int $minimum = 0): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("The [{$key}] field must be an integer.");
        }

        self::assertInteger($value, $key, $minimum);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException("The [{$key}] field is required.");
        }

        $value = $data[$key];

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The [{$key}] field must be a string or null.");
        }

        if ($value === '') {
            throw new InvalidArgumentException("The [{$key}] field must be a non-empty string or null.");
        }

        return $value;
    }

    public static function assertNonEmptyString(string $value, string $key): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("The [{$key}] field must be a non-empty string.");
        }
    }

    public static function assertInteger(int $value, string $key, int $minimum = 0): void
    {
        if ($value < $minimum) {
            throw new InvalidArgumentException("The [{$key}] field must be an integer of at least {$minimum}.");
        }
    }
}
