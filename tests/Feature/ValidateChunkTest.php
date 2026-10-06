<?php

use App\Domains\BulkImport\Messages\ValidateChunk;

it('uses the configured validate routing key as its type', function (): void {
    config(['bulk-imports.messaging.routing_keys.validate_chunk' => 'custom.validate']);

    expect(ValidateChunk::type())->toBe('custom.validate');
});

it('round trips through its data', function (): void {
    $message = ValidateChunk::fromData((new ValidateChunk('import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv'))->data());

    expect([$message->bulkImportId, $message->chunkId, $message->chunkObjectKey])
        ->toBe(['import-1', 'chunk-1', 'consent/import-1/chunks/chunk-000001.csv']);
});

it('rejects missing fields', function (array $data): void {
    ValidateChunk::fromData($data);
})->throws(InvalidArgumentException::class)->with([
    'missing chunk id' => [['bulk_import_id' => 'import-1', 'chunk_object_key' => 'key']],
    'empty object key' => [['bulk_import_id' => 'import-1', 'chunk_id' => 'chunk-1', 'chunk_object_key' => '']],
]);
