<?php

use App\Domains\BulkImport\Handlers\ParseImportHandler;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messages\ValidateChunk;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;

it('registers the bulk import queues with the SDK, with their handlers and settings from config', function (): void {
    $registry = app(MessagingRegistry::class);
    $messaging = config('bulk-imports.messaging');

    expect(collect($registry->queues())->pluck('name')->all())->toBe(array_values($messaging['queues']))
        ->and(collect($registry->exchanges())->pluck('name')->all())->toBe([$messaging['exchange']]);

    $parse = $registry->queue($messaging['queues']['parse']);

    expect($parse->handler)->toBe(ParseImportHandler::class)
        ->and($parse->routingKeys)->toBe([$messaging['routing_keys']['parse_requested']])
        ->and($parse->maxAttempts)->toBe($messaging['consumers']['max_attempts'])
        ->and($parse->retryDelaySeconds)->toBe($messaging['consumers']['retry_delay_seconds'])
        ->and($parse->prefetch)->toBe($messaging['consumers']['prefetch']);
});

it('gives every queue a handler that the SDK can run', function (): void {
    foreach (app(MessagingRegistry::class)->queues() as $queue) {
        expect($queue->handler)->not->toBeNull()
            ->and(is_subclass_of($queue->handler, MessageHandler::class))->toBeTrue();
    }
});

it('binds the routing key of every message the domain publishes to a queue on its exchange', function (string $message): void {
    $instance = match ($message) {
        ParseRequested::class => new ParseRequested('import-1', 'consent/import-1/source/source.csv'),
        ValidateChunk::class => new ValidateChunk('import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv'),
    };

    $bound = collect(app(MessagingRegistry::class)->queues())
        ->filter(fn ($queue) => $queue->exchange === $instance->exchange() && in_array($instance->routingKey(), $queue->routingKeys, true));

    expect($bound)->toHaveCount(1);
})->with([ParseRequested::class, ValidateChunk::class]);

it('scales the validate queue between the consumer limits from config, and leaves the others at one consumer', function (): void {
    config(['bulk-imports.messaging.scaling.validate' => ['min_consumers' => 2, 'max_consumers' => 7, 'scale_down_cooldown_seconds' => 90]]);

    $registry = app(MessagingRegistry::class);
    $queues = config('bulk-imports.messaging.queues');
    $validate = collect($registry->queues())->firstWhere('name', $queues['validate']);
    $parse = collect($registry->queues())->firstWhere('name', $queues['parse']);

    expect($validate->scaling->minConsumers())->toBe(2)
        ->and($validate->scaling->maxConsumers())->toBe(7)
        ->and($validate->scaling->scaleDownCooldownSeconds())->toBe(90)
        ->and($parse->scaling->maxConsumers())->toBe(1);
});
