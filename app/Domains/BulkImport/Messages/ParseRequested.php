<?php

namespace App\Domains\BulkImport\Messages;

use InvalidArgumentException;
use Modules\Core\Features\RabbitMQ\Contracts\Message;

/**
 * Asks the parse worker to split an uploaded CSV into chunks.
 */
final readonly class ParseRequested implements Message
{
    public function __construct(
        public string $bulkImportId,
        public string $sourceObjectKey,
    ) {
        if ($bulkImportId === '' || $sourceObjectKey === '') {
            throw new InvalidArgumentException('A parse request needs an import id and a source object key.');
        }
    }

    public function exchange(): string
    {
        return config('bulk-imports.messaging.exchange');
    }

    public function routingKey(): string
    {
        return config('bulk-imports.messaging.routing_keys.parse_requested');
    }

    public function toPayload(): array
    {
        return [
            'bulk_import_id' => $this->bulkImportId,
            'source_object_key' => $this->sourceObjectKey,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            bulkImportId: (string) ($payload['bulk_import_id'] ?? ''),
            sourceObjectKey: (string) ($payload['source_object_key'] ?? ''),
        );
    }
}
