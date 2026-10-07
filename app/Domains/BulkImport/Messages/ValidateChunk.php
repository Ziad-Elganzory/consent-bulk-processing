<?php

namespace App\Domains\BulkImport\Messages;

use App\Infrastructure\Messaging\Protocol\MessageContract;
use App\Infrastructure\Messaging\Protocol\MessageFields;

final readonly class ValidateChunk implements MessageContract
{
    public function __construct(
        public string $bulkImportId,
        public string $chunkId,
        public string $chunkObjectKey,
    ) {
        MessageFields::nonEmpty($this->bulkImportId, 'bulk_import_id');
        MessageFields::nonEmpty($this->chunkId, 'chunk_id');
        MessageFields::nonEmpty($this->chunkObjectKey, 'chunk_object_key');
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
        $fields = new MessageFields($data);

        return new self(
            bulkImportId: $fields->text('bulk_import_id'),
            chunkId: $fields->text('chunk_id'),
            chunkObjectKey: $fields->text('chunk_object_key'),
        );
    }
}
