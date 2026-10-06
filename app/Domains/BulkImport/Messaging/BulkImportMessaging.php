<?php

namespace App\Domains\BulkImport\Messaging;

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Infrastructure\Messaging\Contracts\ModuleMessaging;
use App\Infrastructure\Messaging\Topology\ExchangeDefinition;
use App\Infrastructure\Messaging\Topology\QueueDefinition;

/**
 * What the bulk import pipeline publishes and consumes. Names come from
 * config('bulk-imports.messaging').
 */
final class BulkImportMessaging implements ModuleMessaging
{
    public function exchanges(): array
    {
        return [new ExchangeDefinition(config('bulk-imports.messaging.exchange'))];
    }

    public function queues(): array
    {
        $exchange = config('bulk-imports.messaging.exchange');
        $queues = config('bulk-imports.messaging.queues');
        $routingKeys = config('bulk-imports.messaging.routing_keys');

        return [
            new QueueDefinition($queues['parse'], $exchange, [ParseRequested::type()]),
            // These routing keys get their message classes when the validate and assemble steps are built.
            new QueueDefinition($queues['validate'], $exchange, [$routingKeys['validate_chunk']]),
            new QueueDefinition($queues['assemble'], $exchange, [$routingKeys['assemble_import']]),
        ];
    }

    public function messages(): array
    {
        return [ParseRequested::class];
    }
}
