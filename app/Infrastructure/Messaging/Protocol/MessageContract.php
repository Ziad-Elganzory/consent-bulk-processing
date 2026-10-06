<?php

namespace App\Infrastructure\Messaging\Protocol;

/**
 * A typed message carried inside a MessageEnvelope.
 *
 * The type is also the routing key the message is published with, so it must
 * match a binding in config/rabbitmq-topology.php.
 */
interface MessageContract
{
    public static function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException when the data is not valid for this message
     */
    public static function fromData(array $data): static;
}
