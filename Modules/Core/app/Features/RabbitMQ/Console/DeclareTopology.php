<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Creates every exchange, queue and binding of the registered modules. Declaring is
 * idempotent, so this runs on every deploy.
 *
 * A queue that already exists with different arguments (another type, retry delay, ...)
 * cannot be changed by declaring it again: the broker refuses with PRECONDITION_FAILED.
 * Each such conflict is reported and the rest is still declared; changing it needs a
 * deliberate migration (delete and re-declare the queue).
 */
class DeclareTopology extends Command
{
    private const int PRECONDITION_FAILED = 406;

    protected $signature = 'rabbitmq:declare-topology';

    protected $description = 'Declare the exchanges, queues and bindings of every module on RabbitMQ';

    private AMQPChannel $channel;

    private int $conflicts = 0;

    public function handle(MessagingRegistry $registry, RabbitMQConnection $connection): int
    {
        $this->channel = $connection->openChannel();

        foreach ($registry->exchanges() as $exchange) {
            $this->runDeclaration("Exchange {$exchange->name} ({$exchange->type->value})", $connection, fn () => $this->channel->exchange_declare(
                $exchange->name, $exchange->type->value, passive: false, durable: true, auto_delete: false,
            ));
        }

        foreach ($registry->queues() as $queue) {
            $this->runDeclaration("Queue {$queue->name}", $connection, fn () => $this->declareQueue($queue->name, $queue->workQueueArguments()));
            $this->runDeclaration("Queue {$queue->retryQueueName()}", $connection, fn () => $this->declareQueue($queue->retryQueueName(), $queue->retryQueueArguments()));
            $this->runDeclaration("Queue {$queue->deadQueueName()}", $connection, fn () => $this->declareQueue($queue->deadQueueName(), $queue->deadQueueArguments()));

            foreach ($queue->routingKeys as $routingKey) {
                $this->runDeclaration("Bind {$queue->exchange} --{$routingKey}--> {$queue->name}", $connection, fn () => $this->channel->queue_bind(
                    $queue->name, $queue->exchange, $routingKey,
                ));
            }
        }

        $connection->close();

        if ($this->conflicts > 0) {
            $this->components->error("{$this->conflicts} declaration(s) conflict with what already exists on the broker; see above.");

            return self::FAILURE;
        }

        $this->components->info('Topology declared.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function declareQueue(string $name, array $arguments): void
    {
        $this->channel->queue_declare(
            $name, passive: false, durable: true, exclusive: false, auto_delete: false, nowait: false, arguments: new AMQPTable($arguments),
        );
    }

    private function runDeclaration(string $description, RabbitMQConnection $connection, callable $declaration): void
    {
        try {
            $this->components->task($description, $declaration);
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() !== self::PRECONDITION_FAILED) {
                throw $exception;
            }

            $this->conflicts++;
            $this->components->warn($exception->getMessage());

            // The broker closes a channel after a failed declaration.
            $this->channel = $connection->openChannel();
        }
    }
}
