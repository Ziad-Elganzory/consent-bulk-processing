<?php

use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Protocol\Messages\ParseRequested;

function parseRequestedEnvelopePayload(array $overrides = []): array
{
    return [
        'message_id' => '0b8f6c1e-6f55-4c1a-9a4e-0d1a8e1c2b3d',
        'type' => 'consent.parse.requested',
        'correlation_id' => 'import-1',
        'occurred_at' => '2026-10-06T09:39:18+00:00',
        'data' => ['bulk_import_id' => 'import-1', 'source_object_key' => 'consent/import-1/source/source.csv'],
        ...$overrides,
    ];
}

it('builds an envelope with a generated id and a timestamp', function (): void {
    $message = new ParseRequested('import-1', 'consent/import-1/source/source.csv');

    $envelope = MessageEnvelope::make($message, correlationId: 'import-1');

    expect($envelope->messageId)->toBeUuid()
        ->and($envelope->type())->toBe('consent.parse.requested')
        ->and($envelope->correlationId)->toBe('import-1')
        ->and($envelope->message)->toBe($message)
        ->and($envelope->occurredAt->isToday())->toBeTrue();
});

it('uses the given message id', function (): void {
    $envelope = MessageEnvelope::make(new ParseRequested('i', 'k'), 'i', messageId: 'fixed-id');

    expect($envelope->messageId)->toBe('fixed-id');
});

it('serialises the type, correlation id and message data', function (): void {
    $envelope = MessageEnvelope::make(new ParseRequested('import-1', 'key'), 'import-1');

    expect($envelope->toArray())->toMatchArray([
        'message_id' => $envelope->messageId,
        'type' => 'consent.parse.requested',
        'correlation_id' => 'import-1',
        'data' => ['bulk_import_id' => 'import-1', 'source_object_key' => 'key'],
    ]);
});

it('survives a round trip through an array', function (): void {
    $envelope = MessageEnvelope::make(new ParseRequested('import-1', 'key'), 'import-1');

    $restored = MessageEnvelope::fromArray($envelope->toArray());

    expect($restored->toArray())->toBe($envelope->toArray())
        ->and($restored->message)->toBeInstanceOf(ParseRequested::class);
});

it('reads a valid payload into a typed message', function (): void {
    $envelope = MessageEnvelope::fromArray(parseRequestedEnvelopePayload());

    expect($envelope->message)->toBeInstanceOf(ParseRequested::class)
        ->and($envelope->message->bulkImportId)->toBe('import-1')
        ->and($envelope->occurredAt->toDateString())->toBe('2026-10-06');
});

it('rejects an invalid envelope', function (array $overrides): void {
    MessageEnvelope::fromArray(parseRequestedEnvelopePayload($overrides));
})->throws(InvalidArgumentException::class)->with([
    'missing message id' => [['message_id' => null]],
    'empty type' => [['type' => '']],
    'unknown type' => [['type' => 'consent.unknown']],
    'missing correlation id' => [['correlation_id' => null]],
    'missing occurred at' => [['occurred_at' => null]],
    'unparseable occurred at' => [['occurred_at' => 'not a date']],
    'missing data' => [['data' => null]],
    'invalid message data' => [['data' => ['bulk_import_id' => 'import-1']]],
]);

it('round trips through json', function (): void {
    $envelope = MessageEnvelope::make(new ParseRequested('import-1', 'key'), 'import-1');

    $restored = MessageEnvelope::fromJson($envelope->toJson());

    expect($restored->toArray())->toBe($envelope->toArray());
});

it('rejects json that is not an object', function (string $json): void {
    MessageEnvelope::fromJson($json);
})->throws(InvalidArgumentException::class)->with([
    'list' => ['[1, 2]'],
    'scalar' => ['"text"'],
]);

it('rejects malformed json', function (): void {
    MessageEnvelope::fromJson('{not json');
})->throws(JsonException::class);
