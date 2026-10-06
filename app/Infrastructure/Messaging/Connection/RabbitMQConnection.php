<?php

namespace App\Infrastructure\Messaging\Connection;

use LogicException;
use PhpAmqpLib\Channel\AMQPChannel;
use Throwable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * The only class that touches the RabbitMQ package. It opens a connection from
 * config('queue.connections.rabbitmq') and hands out its channel.
 */
class RabbitMQConnection
{
    private ?RabbitMQQueue $queue = null;

    public function __construct(private readonly RabbitMQConnector $connector) {}

    /**
     * Returns the open channel, connecting first when needed.
     *
     * @param  array<string, mixed>  $options  package connection options, such as timeouts
     */
    public function channel(array $options = []): AMQPChannel
    {
        if ($this->queue?->getConnection()->isConnected()) {
            return $this->queue->getChannel();
        }

        $config = config('queue.connections.rabbitmq');
        $config['options'] = [...($config['options'] ?? []), ...$options];

        $queue = $this->connector->connect($config);

        if (! $queue instanceof RabbitMQQueue) {
            throw new LogicException('The RabbitMQ connection did not return the expected queue driver.');
        }

        $channel = $queue->getChannel();
        $this->queue = $queue;

        return $channel;
    }

    public function close(): void
    {
        try {
            $this->queue?->close();
        } catch (Throwable) {
            // The connection is already broken, so there is nothing left to close.
        }

        $this->queue = null;
    }
}
