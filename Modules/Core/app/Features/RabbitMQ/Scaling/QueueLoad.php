<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

/**
 * A queue as the broker reports it at one moment.
 */
final readonly class QueueLoad
{
    public function __construct(
        public int $readyMessages,
        public int $consumers,
    ) {}
}
