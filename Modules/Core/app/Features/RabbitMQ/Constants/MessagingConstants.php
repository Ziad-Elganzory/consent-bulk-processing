<?php

namespace Modules\Core\Features\RabbitMQ\Constants;

/**
 * Fixed rules of the messaging layer: how queues are declared, messages are stamped,
 * and the consumer and outbox relay pace themselves.
 */
final class MessagingConstants
{
    // Queues.

    /**
     * Type of every declared queue (x-queue-type): replicated and durable, so messages
     * survive a broker restart or the loss of a cluster node.
     */
    public const string QUEUE_TYPE = 'quorum';

    /**
     * Suffix of a queue's retry companion, where a failed message waits its retry delay
     * before the broker dead-letters it back to the work queue.
     */
    public const string RETRY_QUEUE_SUFFIX = '.retry';

    /**
     * Suffix of a queue's dead companion, where a message that used up its attempts is
     * kept for inspection.
     */
    public const string DEAD_QUEUE_SUFFIX = '.dead';

    // Defaults of a QueueDefinition.

    /**
     * Deliveries of a message before it is moved to the dead queue.
     */
    public const int DEFAULT_MAX_ATTEMPTS = 3;

    /**
     * Seconds a failed message waits in the retry queue before its next delivery.
     */
    public const int DEFAULT_RETRY_DELAY_SECONDS = 30;

    /**
     * Unacknowledged messages the broker hands one consumer at a time; 1 gives the next
     * message to whichever consumer is free, so a slow one does not hoard work.
     */
    public const int DEFAULT_PREFETCH = 1;

    // Message stamping.

    /**
     * Content type of every message body.
     */
    public const string CONTENT_TYPE = 'application/json';

    /**
     * Header carrying the delivery attempt, 1 on the first delivery.
     */
    public const string ATTEMPT_HEADER = 'x-attempt';

    /**
     * Header carrying the error that failed the previous delivery; absent until one fails.
     */
    public const string ERROR_HEADER = 'x-last-error';

    /**
     * Longest error text kept in ERROR_HEADER and in the outbox's last_error
     * (headers share the broker's frame size).
     */
    public const int ERROR_TEXT_MAX_LENGTH = 1000;

    // Timeouts.

    /**
     * Seconds to wait for the broker to confirm a published message before it counts
     * as not published.
     */
    public const float PUBLISH_CONFIRM_TIMEOUT = 5.0;

    /**
     * Seconds the consumer blocks waiting for a delivery before re-checking its stop
     * conditions.
     */
    public const float CONSUMER_WAIT_TIMEOUT = 1.0;

    // Worker supervisor (rabbitmq:work). Default scaling of a queue that declares none.

    /**
     * Consumers kept running for a queue at all times.
     */
    public const int DEFAULT_MIN_CONSUMERS = 1;

    /**
     * Most consumers run for a queue, however long it gets.
     */
    public const int DEFAULT_MAX_CONSUMERS = 1;

    /**
     * Seconds a queue must stay quiet before its extra consumers are stopped, so the
     * count does not flap.
     */
    public const int DEFAULT_SCALE_DOWN_COOLDOWN_SECONDS = 60;

    /**
     * Consumers started per queue per tick at most, so a burst does not start them all
     * at once.
     */
    public const int SCALE_UP_STEP = 2;

    /**
     * Seconds between two looks at the queues.
     */
    public const int SUPERVISOR_TICK_SECONDS = 3;

    /**
     * Most consumer processes one supervisor runs across all queues (protects the
     * container and the database's connections).
     */
    public const int SUPERVISOR_MAX_PROCESSES = 20;

    /**
     * Seconds a consumer runs before it exits and is restarted fresh, like queue:work's
     * --max-time.
     */
    public const int CONSUMER_MAX_SECONDS = 3600;

    /**
     * Seconds stopping consumers get to finish their message before they are killed.
     */
    public const int CONSUMER_STOP_TIMEOUT_SECONDS = 120;

    // Outbox relay.

    /**
     * Messages published per database transaction; the default of --batch.
     */
    public const int OUTBOX_RELAY_BATCH_SIZE = 100;

    /**
     * Seconds to sleep when the outbox had less than a full batch to publish.
     */
    public const int OUTBOX_RELAY_IDLE_SECONDS = 1;

    /**
     * Days a published outbox message is kept before model:prune deletes it; unpublished
     * messages are never pruned.
     */
    public const int OUTBOX_PRUNE_AFTER_DAYS = 7;
}
