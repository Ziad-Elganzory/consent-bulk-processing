<?php

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messaging\BulkImportMessaging;
use App\Infrastructure\Messaging\Contracts\ModuleMessaging;
use App\Infrastructure\Messaging\Topology\ExchangeDefinition;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use App\Infrastructure\Messaging\Topology\QueueDefinition;

function queueDefinition(string $name, string $exchange, array $routingKeys, ?string $handler = null, int $maxAttempts = 3): QueueDefinition
{
    return new QueueDefinition($name, $exchange, $routingKeys, $maxAttempts, retryDelaySeconds: 30, prefetch: 1, handler: $handler);
}

function moduleMessaging(array $exchanges, array $queues, array $messages = []): ModuleMessaging
{
    return new class($exchanges, $queues, $messages) implements ModuleMessaging
    {
        public function __construct(private array $exchanges, private array $queues, private array $messages) {}

        public function exchanges(): array
        {
            return $this->exchanges;
        }

        public function queues(): array
        {
            return $this->queues;
        }

        public function messages(): array
        {
            return $this->messages;
        }
    };
}

it('builds the registry from the bulk import declaration', function (): void {
    $registry = app(MessagingRegistry::class);

    expect(collect($registry->queues())->pluck('name')->all())->toBe(array_values(config('bulk-imports.messaging.queues')))
        ->and($registry->exchangeFor(ParseRequested::type()))->toBe(config('bulk-imports.messaging.exchange'));
});

it('rebuilds a registered message from its type and data', function (): void {
    $message = app(MessagingRegistry::class)->message(ParseRequested::type(), [
        'bulk_import_id' => 'import-1',
        'source_object_key' => 'consent/import-1/source/source.csv',
    ]);

    expect($message)->toBeInstanceOf(ParseRequested::class);
});

it('rejects an unknown message type', function (): void {
    app(MessagingRegistry::class)->message('consent.unknown', []);
})->throws(InvalidArgumentException::class, 'Unsupported message type');

it('rejects contradicting declarations', function (ModuleMessaging $module, string $error): void {
    expect(fn () => new MessagingRegistry([$module]))->toThrow(LogicException::class, $error);
})->with([
    'queue on an undeclared exchange' => fn () => [
        moduleMessaging([], [queueDefinition('q', 'missing', ['k'])]),
        'undeclared exchange',
    ],
    'routing key bound to two queues' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [queueDefinition('a', 'x', ['k']), queueDefinition('b', 'x', ['k'])]),
        'more than one queue',
    ],
    'queue declared twice' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [queueDefinition('a', 'x', ['k']), queueDefinition('a', 'x', ['j'])]),
        'declared twice',
    ],
    'message without a binding' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [], [ParseRequested::class]),
        'not bound',
    ],
    'handler that is not a MessageHandler' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [queueDefinition('a', 'x', ['k'], handler: stdClass::class)]),
        'does not implement MessageHandler',
    ],
    'attempts below one' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [queueDefinition('a', 'x', ['k'], maxAttempts: 0)]),
        'at least 1',
    ],
]);

it('looks up a declared queue by name', function (): void {
    $name = config('bulk-imports.messaging.queues.parse');

    expect(app(MessagingRegistry::class)->queue($name)->name)->toBe($name);
});

it('rejects an undeclared queue name', function (): void {
    app(MessagingRegistry::class)->queue('missing');
})->throws(InvalidArgumentException::class, 'not declared');

it('takes consumer settings from config', function (): void {
    config(['bulk-imports.messaging.consumers' => ['max_attempts' => 5, 'retry_delay_seconds' => 12, 'prefetch' => 2]]);

    $queue = (new MessagingRegistry([new BulkImportMessaging]))->queue(config('bulk-imports.messaging.queues.parse'));

    expect([$queue->maxAttempts, $queue->retryDelaySeconds, $queue->prefetch])->toBe([5, 12, 2]);
});

it('derives the retry and dead queues from the work queue', function (): void {
    $queue = queueDefinition('jobs', 'x', ['k']);

    expect($queue->retryQueueName())->toBe('jobs.retry')
        ->and($queue->deadQueueName())->toBe('jobs.dead')
        ->and($queue->workQueueArguments())->toMatchArray(['x-dead-letter-exchange' => '', 'x-dead-letter-routing-key' => 'jobs.dead'])
        ->and($queue->retryQueueArguments())->toMatchArray([
            'x-message-ttl' => 30_000,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => 'jobs',
        ]);
});
