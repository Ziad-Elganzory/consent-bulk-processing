<?php

namespace App\Domains\BulkImport\Messages;

use App\Infrastructure\Messaging\Protocol\MessageContract;
use App\Infrastructure\Messaging\Protocol\MessageFields;

final readonly class ParseRequested implements MessageContract
{
    public function __construct(
        public string $bulkImportId,
        public string $sourceObjectKey,
    ) {
        MessageFields::nonEmpty($this->bulkImportId, 'bulk_import_id');
        MessageFields::nonEmpty($this->sourceObjectKey, 'source_object_key');
    }

    public static function type(): string
    {
        return config('bulk-imports.messaging.routing_keys.parse_requested');
    }

    /**
     * @return array{bulk_import_id: string, source_object_key: string}
     */
    public function data(): array
    {
        return [
            'bulk_import_id' => $this->bulkImportId,
            'source_object_key' => $this->sourceObjectKey,
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
            sourceObjectKey: $fields->text('source_object_key'),
        );
    }
}
