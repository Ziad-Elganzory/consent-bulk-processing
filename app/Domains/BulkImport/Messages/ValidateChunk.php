<?php

namespace App\Domains\BulkImport\Messages;

use App\Infrastructure\Messaging\Protocol\MessageContract;
use App\Infrastructure\Messaging\Protocol\MessageData;

final readonly class ValidateChunk implements MessageContract
{
    public function __construct(
        public string $bulkImportId,
        public string $chunkId,
        public string $chunkObjectKey,
    ) {
        MessageData::assertNonEmptyString($this->bulkImportId, 'bulk_import_id');
        MessageData::assertNonEmptyString($this->chunkId, 'chunk_id');
        MessageData::assertNonEmptyString($this->chunkObjectKey, 'chunk_object_key');
    }

    public static function type(): string
    {
        return config('bulk-imports.messaging.routing_keys.validate_chunk');
    }

    /**
     * @return array{bulk_import_id: string, chunk_id: string, chunk_object_key: string}
     */
    public function data(): array
    {
        return [
            'bulk_import_id' => $this->bulkImportId,
            'chunk_id' => $this->chunkId,
            'chunk_object_key' => $this->chunkObjectKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromData(array $data): static
    {
        return new self(
            bulkImportId: MessageData::requiredString($data, 'bulk_import_id'),
            chunkId: MessageData::requiredString($data, 'chunk_id'),
            chunkObjectKey: MessageData::requiredString($data, 'chunk_object_key'),
        );
    }
}
