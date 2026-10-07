<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Wire\AMQPTable;

#[Signature('rabbitmq:topology:declare')]
#[Description('Declare the exchanges, queues (with their retry and dead queues) and bindings of every registered module.')]
class DeclareRabbitMqTopology extends Command
{
    public function handle(MessagingRegistry $registry, RabbitMQConnection $connection): int
    {
        $channel = $connection->channel();

        try {
            foreach ($registry->exchanges() as $exchange) {
                $channel->exchange_declare($exchange->name, $exchange->type, durable: true, auto_delete: false);
            }

            foreach ($registry->queues() as $queue) {
                foreach ($queue->declarations() as $name => $arguments) {
                    $channel->queue_declare($name, durable: true, auto_delete: false, arguments: new AMQPTable($arguments));
                }

                // Only the work queue is bound; retries and dead letters travel through the default exchange.
                foreach ($queue->routingKeys as $routingKey) {
                    $channel->queue_bind($queue->name, $queue->exchange, $routingKey);
                }
            }
        } finally {
            $connection->close();
        }

        $this->info('RabbitMQ topology declared successfully.');

        return self::SUCCESS;
    }
}
