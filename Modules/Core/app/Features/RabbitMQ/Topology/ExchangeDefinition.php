<?php

namespace Modules\Core\Features\RabbitMQ\Topology;

/**
 * An exchange a module publishes to. Always declared durable and never auto-deleted.
 */
final readonly class ExchangeDefinition
{
    public function __construct(
        public string $name,
        public ExchangeType $type = ExchangeType::Topic,
    ) {}
}
