<?php

use App\Domains\BulkImport\Messages\ParseRequested;

it('publishes to the configured exchange and parse routing key', function (): void {
    config([
        'bulk-imports.messaging.exchange' => 'custom.exchange',
        'bulk-imports.messaging.routing_keys.parse_requested' => 'custom.parse',
    ]);

    $message = new ParseRequested('import-1', 'consent/import-1/source/source.csv');

    expect($message->exchange())->toBe('custom.exchange')
        ->and($message->routingKey())->toBe('custom.parse');
});

it('round trips through its payload', function (): void {
    $message = new ParseRequested('import-1', 'consent/import-1/source/source.csv');

    $restored = ParseRequested::fromPayload($message->toPayload());

    expect($restored->bulkImportId)->toBe('import-1')
        ->and($restored->sourceObjectKey)->toBe('consent/import-1/source/source.csv');
});

it('rejects a payload with empty or missing fields', function (array $payload): void {
    ParseRequested::fromPayload($payload);
})->throws(InvalidArgumentException::class)->with([
    'missing import id' => [['source_object_key' => 'key']],
    'empty import id' => [['bulk_import_id' => '', 'source_object_key' => 'key']],
    'missing object key' => [['bulk_import_id' => 'import-1']],
]);

it('rejects empty values when constructed directly', function (): void {
    new ParseRequested('', 'key');
})->throws(InvalidArgumentException::class);
