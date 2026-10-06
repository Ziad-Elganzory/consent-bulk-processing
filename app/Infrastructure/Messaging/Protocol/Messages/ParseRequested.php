<?php

namespace App\Infrastructure\Messaging\Protocol\Messages;

use App\Infrastructure\Messaging\Protocol\MessageContract;
use App\Infrastructure\Messaging\Protocol\MessageData;

final readonly class ParseRequested implements MessageContract
{
    public function __construct(
        public string $bulkImportId,
        public string $sourceObjectKey,
    ) {
        MessageData::assertNonEmptyString($this->bulkImportId, 'bulk_import_id');
        MessageData::assertNonEmptyString($this->sourceObjectKey, 'source_object_key');
    }

    public static function type(): string
    {
        return 'consent.parse.requested';
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
        return new self(
            bulkImportId: MessageData::requiredString($data, 'bulk_import_id'),
            sourceObjectKey: MessageData::requiredString($data, 'source_object_key'),
        );
    }
}
