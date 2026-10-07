<?php

namespace App\Domains\BulkImport\Messages;

use InvalidArgumentException;
use Modules\Core\Features\RabbitMQ\Contracts\Message;

/**
 * Asks a validation worker to check one chunk of an import.
 */
final readonly class ValidateChunk implements Message
{
    public function __construct(
        public string $bulkImportId,
        public string $chunkId,
        public string $chunkObjectKey,
    ) {
        if ($bulkImportId === '' || $chunkId === '' || $chunkObjectKey === '') {
            throw new InvalidArgumentException('A chunk validation request needs an import id, a chunk id and a chunk object key.');
        }
    }

    public function exchange(): string
    {
        return config('bulk-imports.messaging.exchange');
    }

    public function routingKey(): string
    {
        return config('bulk-imports.messaging.routing_keys.validate_chunk');
    }

    public function toPayload(): array
    {
        return [
            'bulk_import_id' => $this->bulkImportId,
            'chunk_id' => $this->chunkId,
            'chunk_object_key' => $this->chunkObjectKey,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            bulkImportId: (string) ($payload['bulk_import_id'] ?? ''),
            chunkId: (string) ($payload['chunk_id'] ?? ''),
            chunkObjectKey: (string) ($payload['chunk_object_key'] ?? ''),
        );
    }
}
