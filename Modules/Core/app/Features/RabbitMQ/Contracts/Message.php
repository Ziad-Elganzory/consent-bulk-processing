<?php

namespace Modules\Core\Features\RabbitMQ\Contracts;

/**
 * An event a module publishes. Implementations are small readonly classes whose
 * exchange and routing key come from the owning module's ModuleMessaging constants.
 */
interface Message
{
    public function exchange(): string;

    public function routingKey(): string;

    /**
     * @return array<string, mixed> JSON-serialisable data
     */
    public function toPayload(): array;

    /**
     * @param  array<string, mixed>  $payload  as produced by toPayload()
     */
    public static function fromPayload(array $payload): static;
}
