<?php

namespace App\Infrastructure\Messaging\Topology;

/**
 * A durable quorum queue, bound to an exchange under one or more routing keys
 * (message types).
 */
final readonly class QueueDefinition
{
    /**
     * @param  list<string>  $routingKeys
     */
    public function __construct(
        public string $name,
        public string $exchange,
        public array $routingKeys,
    ) {}
}
