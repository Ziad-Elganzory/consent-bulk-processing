<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

/**
 * How many consumers a queue may have across every worker supervisor (rabbitmq:work).
 * Read on every supervisor tick, so an implementation backed by settings applies changes
 * without a restart.
 */
interface ConsumerScaling
{
    /** Consumers kept running even when the queue is empty. */
    public function minConsumers(): int;

    /** Consumers allowed at most while messages wait. */
    public function maxConsumers(): int;

    /** Seconds the queue must stay empty before an extra consumer is stopped. */
    public function scaleDownCooldownSeconds(): int;
}
