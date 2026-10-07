<?php

namespace Modules\Core\Features\RabbitMQ\Contracts;

use Modules\Core\Features\RabbitMQ\Exceptions\MessageNotPublished;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;

/**
 * Sends envelopes to the broker and returns only once the broker has confirmed them.
 *
 * Modules do not publish directly: they record messages in the Outbox inside their
 * database transaction, and the outbox relay publishes them.
 */
interface MessagePublisher
{
    /**
     * Publishes to an exchange under the envelope's routing key. A message no queue is
     * bound for is dropped by the broker, which is not an error.
     *
     * @throws MessageNotPublished
     */
    public function publish(string $exchange, Envelope $envelope): void;

    /**
     * Sends straight to one queue through the default exchange (retry and dead queues).
     *
     * @throws MessageNotPublished also when the queue does not exist
     */
    public function sendToQueue(string $queue, Envelope $envelope): void;
}
