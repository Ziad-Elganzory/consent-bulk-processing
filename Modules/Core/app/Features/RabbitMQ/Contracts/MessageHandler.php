<?php

namespace Modules\Core\Features\RabbitMQ\Contracts;

use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Handles the messages of one queue (QueueDefinition::$handler). Resolved from the container.
 *
 * Delivery is at least once: the same message can arrive again (redelivery after a
 * crash, a relay publishing twice), so handle() must be idempotent — Envelope::$messageId
 * identifies a message across deliveries.
 */
interface MessageHandler
{
    /**
     * Throwing sends the message to the retry queue, or to the dead queue once its attempts are used up.
     */
    public function handle(Envelope $envelope): void;

    /**
     * Called once the message has used up its attempts and been moved to the dead queue.
     */
    public function failed(Envelope $envelope, Throwable $exception): void;
}
