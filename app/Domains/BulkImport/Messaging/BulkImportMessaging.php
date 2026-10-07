<?php

namespace App\Domains\BulkImport\Messaging;

use App\Domains\BulkImport\Handlers\AssembleImportHandler;
use App\Domains\BulkImport\Handlers\ParseImportHandler;
use App\Domains\BulkImport\Handlers\ValidateChunkHandler;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Contracts\ModuleMessaging;
use Modules\Core\Features\RabbitMQ\Topology\ExchangeDefinition;
use Modules\Core\Features\RabbitMQ\Topology\ExchangeType;
use Modules\Core\Features\RabbitMQ\Topology\QueueDefinition;

/**
 * What the bulk import pipeline publishes and consumes. Names and consumer settings
 * come from config('bulk-imports.messaging').
 */
final class BulkImportMessaging implements ModuleMessaging
{
    public function exchanges(): array
    {
        return [new ExchangeDefinition(config('bulk-imports.messaging.exchange'), ExchangeType::Direct)];
    }

    public function queues(): array
    {
        return [
            $this->queue('parse', 'parse_requested', ParseImportHandler::class),
            $this->queue('validate', 'validate_chunk', ValidateChunkHandler::class),
            $this->queue('assemble', 'assemble_import', AssembleImportHandler::class),
        ];
    }

    /**
     * @param  string  $queue  the queue's key under messaging.queues
     * @param  string  $routingKey  the message's key under messaging.routing_keys
     * @param  class-string<MessageHandler>  $handler
     */
    private function queue(string $queue, string $routingKey, string $handler): QueueDefinition
    {
        $messaging = config('bulk-imports.messaging');

        return new QueueDefinition(
            name: $messaging['queues'][$queue],
            exchange: $messaging['exchange'],
            routingKeys: [$messaging['routing_keys'][$routingKey]],
            handler: $handler,
            maxAttempts: $messaging['consumers']['max_attempts'],
            retryDelaySeconds: $messaging['consumers']['retry_delay_seconds'],
            prefetch: $messaging['consumers']['prefetch'],
        );
    }
}
