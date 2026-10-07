<?php

namespace App\Infrastructure\Messaging\Protocol;

/**
 * A message class: plain, immutable, holding IDs and object keys only.
 *
 * Its type doubles as the routing key it is published with, so every type must be
 * bound to a queue by some DeclaresMessaging implementation.
 */
interface MessageContract
{
    public static function type(): string;

    /**
     * The fields to send.
     *
     * @return array<string, mixed>
     */
    public function data(): array;

    /**
     * Builds the message back from received fields.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException when a field is missing or invalid
     */
    public static function fromData(array $data): static;
}
