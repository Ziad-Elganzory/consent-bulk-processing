<?php

namespace App\Infrastructure\Messaging\Consuming;

/**
 * How a consumer settled one delivery with RabbitMQ.
 */
enum Settlement: string
{
    /** The handler finished; the message is removed from the queue. */
    case Acknowledged = 'acknowledged';

    /** The handler failed; a copy waits in the retry queue for its next attempt. */
    case RetryScheduled = 'retry-scheduled';

    /** The handler failed on the final attempt; a copy is kept in the dead queue. */
    case MovedToDeadQueue = 'moved-to-dead-queue';

    /** The body could not be read as an envelope; RabbitMQ moves it to the dead queue. */
    case Unreadable = 'unreadable';
}
