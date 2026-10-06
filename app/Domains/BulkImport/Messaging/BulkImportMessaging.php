<?php

namespace App\Domains\BulkImport\Messaging;

use App\Domains\BulkImport\Handlers\ParseImportHandler;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messages\ValidateChunk;
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
        // The validate and assemble handlers are added as those workers are built.
        return [
            $this->queue('parse', [ParseRequested::type()], ParseImportHandler::class),
            $this->queue('validate', [ValidateChunk::type()]),
            $this->queue('assemble', [config('bulk-imports.messaging.routing_keys.assemble_import')]),
        ];
    }

    public function messages(): array
    {
        return [ParseRequested::class, ValidateChunk::class];
    }

    /**
     * @param  string  $key  the queue's key under messaging.queues
     * @param  list<string>  $routingKeys
     * @param  class-string<MessageHandler>|null  $handler
     */
    private function queue(string $key, array $routingKeys, ?string $handler = null): QueueDefinition
    {
        $messaging = config('bulk-imports.messaging');

        return new QueueDefinition(
            name: $messaging['queues'][$key],
            exchange: $messaging['exchange'],
            routingKeys: $routingKeys,
            maxAttempts: $messaging['consumers']['max_attempts'],
            retryDelaySeconds: $messaging['consumers']['retry_delay_seconds'],
            prefetch: $messaging['consumers']['prefetch'],
            handler: $handler,
        );
    }
}
