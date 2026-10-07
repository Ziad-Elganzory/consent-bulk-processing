<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;

class ListTopology extends Command
{
    protected $signature = 'rabbitmq:topology';

    protected $description = 'List every exchange and queue the modules declare';

    public function handle(MessagingRegistry $registry): int
    {
        $this->table(
            ['Module', 'Exchange', 'Type'],
            array_map(fn ($exchange) => [
                class_basename($registry->ownerOf($exchange)),
                $exchange->name,
                $exchange->type->value,
            ], $registry->exchanges()),
        );

        $this->table(
            ['Module', 'Queue', 'Exchange', 'Routing keys', 'Handler', 'Attempts', 'Retry', 'Prefetch'],
            array_map(fn ($queue) => [
                class_basename($registry->ownerOf($queue)),
                $queue->name,
                $queue->exchange,
                implode(', ', $queue->routingKeys),
                class_basename($queue->handler),
                $queue->maxAttempts,
                "{$queue->retryDelaySeconds}s",
                $queue->prefetch,
            ], $registry->queues()),
        );

        return self::SUCCESS;
    }
}
