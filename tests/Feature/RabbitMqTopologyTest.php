<?php

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Infrastructure\Messaging\Contracts\ModuleMessaging;
use App\Infrastructure\Messaging\Topology\ExchangeDefinition;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use App\Infrastructure\Messaging\Topology\QueueDefinition;

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
        moduleMessaging([], [new QueueDefinition('q', 'missing', ['k'])]),
        'undeclared exchange',
    ],
    'routing key bound to two queues' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [new QueueDefinition('a', 'x', ['k']), new QueueDefinition('b', 'x', ['k'])]),
        'more than one queue',
    ],
    'queue declared twice' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [new QueueDefinition('a', 'x', ['k']), new QueueDefinition('a', 'x', ['j'])]),
        'declared twice',
    ],
    'message without a binding' => fn () => [
        moduleMessaging([new ExchangeDefinition('x')], [], [ParseRequested::class]),
        'not bound',
    ],
]);
