<?php

namespace App\Infrastructure\Messaging\Contracts;

use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use Throwable;

/**
 * Handles the messages of one queue (QueueDefinition::$handler). Resolved from the
 * container for every delivery.
 *
 * Delivery is at least once, so handle() must be idempotent. MessageEnvelope::$messageId
 * identifies a message across deliveries.
 */
interface MessageHandler
{
    /**
     * Throwing sends the message to the retry queue, or to the dead queue once its attempts are used up.
     */
    public function handle(MessageEnvelope $envelope): void;

    /**
     * Called once, after the last attempt failed and the message was moved to the dead queue.
     */
    public function failed(MessageEnvelope $envelope, Throwable $exception): void;
}
