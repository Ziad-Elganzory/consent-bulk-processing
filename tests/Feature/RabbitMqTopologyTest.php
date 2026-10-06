<?php

use App\Infrastructure\Messaging\Protocol\MessageContract;

/**
 * @return list<class-string<MessageContract>>
 */
function messageClasses(): array
{
    return collect(glob(app_path('Infrastructure/Messaging/Protocol/Messages/*.php')))
        ->map(fn (string $path): string => 'App\\Infrastructure\\Messaging\\Protocol\\Messages\\'.basename($path, '.php'))
        ->all();
}

it('declares the exchange the relay publishes to', function (): void {
    $topology = config('rabbitmq-topology');

    expect($topology['exchanges'])->toHaveKey($topology['command_exchange']);
});

it('binds only declared exchanges and queues', function (): void {
    $topology = config('rabbitmq-topology');

    foreach ($topology['bindings'] as $binding) {
        expect($topology['exchanges'])->toHaveKey($binding['exchange'])
            ->and($topology['queues'])->toHaveKey($binding['queue']);
    }
});

it('binds every message type to a queue on the command exchange', function (): void {
    $topology = config('rabbitmq-topology');
    $boundKeys = collect($topology['bindings'])
        ->where('exchange', $topology['command_exchange'])
        ->pluck('routing_key')
        ->all();

    expect(messageClasses())->not->toBeEmpty();

    foreach (messageClasses() as $messageClass) {
        expect($boundKeys)->toContain($messageClass::type());
    }
});
