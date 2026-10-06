<?php

namespace App\Infrastructure\Messaging\Topology;

/**
 * A durable exchange a module publishes to.
 */
final readonly class ExchangeDefinition
{
    public function __construct(
        public string $name,
        public string $type = 'direct',
    ) {}
}
