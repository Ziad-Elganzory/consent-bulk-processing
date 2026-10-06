<?php

use App\Infrastructure\Messaging\Outbox\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use App\Infrastructure\Messaging\Outbox\Publishers\AmqpOutboxPublisher;
use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Protocol\Messages\ParseRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * These tests only cover the validation that runs before a connection is
 * opened, so they need no RabbitMQ broker.
 */

function validOutboxMessage(): OutboxMessage
{
    return OutboxMessage::enqueue(MessageEnvelope::make(
        new ParseRequested('import-1', 'consent/import-1/source/source.csv'),
        correlationId: 'import-1',
    ));
}

it('rebuilds a typed envelope from the stored payload', function (): void {
    $message = validOutboxMessage()->refresh();

    $envelope = $message->envelope();

    expect($envelope->messageId)->toBe($message->getKey())
        ->and($envelope->type())->toBe($message->routing_key)
        ->and($envelope->message)->toBeInstanceOf(ParseRequested::class);
});

it('refuses to publish a message whose payload is not a valid envelope', function (): void {
    $message = OutboxMessage::create([
        'routing_key' => 'consent.parse.requested',
        'payload' => ['bulk_import_id' => 'abc'],
    ]);

    app(AmqpOutboxPublisher::class)->publish($message);
})->throws(InvalidArgumentException::class);

it('refuses to publish a message of an unknown type', function (): void {
    $message = validOutboxMessage();
    $message->forceFill([
        'routing_key' => 'consent.unknown',
        'payload' => [...$message->payload, 'type' => 'consent.unknown'],
    ])->save();

    app(AmqpOutboxPublisher::class)->publish($message);
})->throws(InvalidArgumentException::class, 'Unsupported message type');

it('refuses to publish when the routing key does not match the envelope type', function (): void {
    $message = validOutboxMessage();
    $message->forceFill(['routing_key' => 'consent.chunk.validate'])->save();

    app(AmqpOutboxPublisher::class)->publish($message);
})->throws(RuntimeException::class, 'routing key');

it('records the error and does not publish an invalid row through the relay', function (): void {
    $message = OutboxMessage::create([
        'routing_key' => 'consent.parse.requested',
        'payload' => ['bulk_import_id' => 'abc'],
    ]);

    $this->artisan('outbox:relay --once')->assertSuccessful();

    $message->refresh();

    expect($message->published_at)->toBeNull()
        ->and($message->last_error)->toContain('must be')
        ->and($message->available_at->isFuture())->toBeTrue();
});

it('reports an unreachable broker as a temporary failure', function (): void {
    config(['queue.connections.rabbitmq.hosts.0.port' => 1]);
    $message = validOutboxMessage();

    app(AmqpOutboxPublisher::class)->publish($message);
})->throws(TransientPublishFailure::class, 'RabbitMQ unavailable');
