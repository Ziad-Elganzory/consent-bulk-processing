<?php

namespace App\Domains\BulkImport\Messaging;

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Infrastructure\Messaging\Contracts\MessageHandler;
use App\Infrastructure\Messaging\Contracts\ModuleMessaging;
use App\Infrastructure\Messaging\Topology\ExchangeDefinition;
use App\Infrastructure\Messaging\Topology\QueueDefinition;

/**
 * What the bulk import pipeline publishes and consumes. Names and consumer settings
 * come from config('bulk-imports.messaging').
 */
final class BulkImportMessaging implements ModuleMessaging
{
    public function exchanges(): array
    {
        return [new ExchangeDefinition(config('bulk-imports.messaging.exchange'))];
    }

    public function queues(): array
    {
        $queues = config('bulk-imports.messaging.queues');
        $routingKeys = config('bulk-imports.messaging.routing_keys');

        // Handlers are added as each worker is built.
        return [
            $this->queue($queues['parse'], [ParseRequested::type()]),
            $this->queue($queues['validate'], [$routingKeys['validate_chunk']]),
            $this->queue($queues['assemble'], [$routingKeys['assemble_import']]),
        ];
    }

    public function messages(): array
    {
        return [ParseRequested::class];
    }

    /**
     * @param  list<string>  $routingKeys
     * @param  class-string<MessageHandler>|null  $handler
     */
    private function queue(string $name, array $routingKeys, ?string $handler = null): QueueDefinition
    {
        $consumers = config('bulk-imports.messaging.consumers');

        return new QueueDefinition(
            name: $name,
            exchange: config('bulk-imports.messaging.exchange'),
            routingKeys: $routingKeys,
            maxAttempts: $consumers['max_attempts'],
            retryDelaySeconds: $consumers['retry_delay_seconds'],
            prefetch: $consumers['prefetch'],
            handler: $handler,
        );
    }
}
