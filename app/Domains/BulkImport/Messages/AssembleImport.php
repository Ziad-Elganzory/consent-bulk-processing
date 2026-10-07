<?php

namespace App\Domains\BulkImport\Messages;

use InvalidArgumentException;
use Modules\Core\Features\RabbitMQ\Contracts\Message;

/**
 * Asks the assembler to combine the validated chunks of an import into its final result.
 */
final readonly class AssembleImport implements Message
{
    public function __construct(public string $bulkImportId)
    {
        if ($bulkImportId === '') {
            throw new InvalidArgumentException('An assemble request needs an import id.');
        }
    }

    public function exchange(): string
    {
        return config('bulk-imports.messaging.exchange');
    }

    public function routingKey(): string
    {
        return config('bulk-imports.messaging.routing_keys.assemble_import');
    }

    public function toPayload(): array
    {
        return ['bulk_import_id' => $this->bulkImportId];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(bulkImportId: (string) ($payload['bulk_import_id'] ?? ''));
    }
}
