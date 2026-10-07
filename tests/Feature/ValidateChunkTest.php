<?php

use App\Domains\BulkImport\Messages\ValidateChunk;

it('publishes to the configured exchange and validate routing key', function (): void {
    config([
        'bulk-imports.messaging.exchange' => 'custom.exchange',
        'bulk-imports.messaging.routing_keys.validate_chunk' => 'custom.validate',
    ]);

    $message = new ValidateChunk('import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv');

    expect($message->exchange())->toBe('custom.exchange')
        ->and($message->routingKey())->toBe('custom.validate');
});

it('round trips through its payload', function (): void {
    $message = ValidateChunk::fromPayload((new ValidateChunk('import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv'))->toPayload());

    expect([$message->bulkImportId, $message->chunkId, $message->chunkObjectKey])
        ->toBe(['import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv']);
});

it('rejects a payload with missing fields', function (array $payload): void {
    ValidateChunk::fromPayload($payload);
})->throws(InvalidArgumentException::class)->with([
    'missing chunk id' => [['bulk_import_id' => 'import-1', 'chunk_object_key' => 'key']],
    'empty object key' => [['bulk_import_id' => 'import-1', 'chunk_id' => 'chunk-1', 'chunk_object_key' => '']],
]);
