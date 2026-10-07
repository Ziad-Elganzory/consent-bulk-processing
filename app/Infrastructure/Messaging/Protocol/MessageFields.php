<?php

namespace App\Infrastructure\Messaging\Protocol;

use InvalidArgumentException;

/**
 * Typed access to the fields of a decoded message. Every failure names the field, so a
 * rejected message says exactly what was wrong with it.
 */
final readonly class MessageFields
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(private array $fields) {}

    /**
     * A field that must be a non-empty string.
     */
    public function text(string $name): string
    {
        $value = $this->fields[$name] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Field [{$name}] is missing or is not a string.");
        }

        return self::nonEmpty($value, $name);
    }

    /**
     * Returns $value unchanged, or throws when it is an empty string. Message constructors
     * use it so an invalid message cannot be built in code either.
     */
    public static function nonEmpty(string $value, string $name): string
    {
        if ($value === '') {
            throw new InvalidArgumentException("Field [{$name}] must not be empty.");
        }

        return $value;
    }
}
