<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Reads a queue's ready messages and consumer count with a passive declare: no management
 * plugin needed, and nothing about the queue changes.
 */
class QueueInspector
{
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly RabbitMQConnection $connection) {}

    public function load(string $queue): QueueLoad
    {
        if (! $this->channel?->is_open()) {
            $this->channel = $this->connection->openChannel();
        }

        [, $readyMessages, $consumers] = $this->channel->queue_declare($queue, passive: true);

        return new QueueLoad((int) $readyMessages, (int) $consumers);
    }
}
