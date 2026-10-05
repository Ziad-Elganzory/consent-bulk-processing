<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;

#[Signature('rabbitmq:topology:declare')]
#[Description('Declare the application RabbitMQ topology.')]
class DeclareRabbitMqTopology extends Command
{
    protected $signature = 'rabbitmq:topology:declare';

    protected $description = 'Declare the application RabbitMQ topology.';

    public function handle(RabbitMQConnector $connector): int
    {
        $topology = config('rabbitmq-topology');
        $rabbitMq = $connector->connect(config('queue.connections.rabbitmq'));

        try {
            foreach ($topology['exchanges'] as $name => $exchange) {
                $rabbitMq->declareExchange(
                    $name,
                    $exchange['type'],
                    $exchange['durable'],
                    $exchange['auto_delete'],
                );
            }

            foreach ($topology['queues'] as $name => $queue) {
                $arguments = $queue['arguments'] ?? [];

                if ($queue['type'] === 'quorum') {
                    $arguments['x-queue-type'] = 'quorum';
                }

                $rabbitMq->declareQueue(
                    $name,
                    $queue['durable'],
                    $queue['auto_delete'],
                    $arguments,
                );
            }

            foreach ($topology['bindings'] as $binding) {
                $rabbitMq->bindQueue(
                    $binding['queue'],
                    $binding['exchange'],
                    $binding['routing_key'],
                );
            }

            $this->info('RabbitMQ topology declared successfully.');

            return self::SUCCESS;
        } finally {
            $rabbitMq->close();
        }
    }
}
