<?php

namespace App\Domains\BulkImport\Handlers;

use App\Domains\BulkImport\Messages\AssembleImport;
use App\Domains\BulkImport\Services\ImportAssembler;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Connects the assemble queue to the assembler: reads the message and hands it over.
 */
final class AssembleImportHandler implements MessageHandler
{
    public function __construct(private readonly ImportAssembler $assembler) {}

    public function handle(Envelope $envelope): void
    {
        $this->assembler->assemble(AssembleImport::fromPayload($envelope->payload));
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        $this->assembler->fail(
            AssembleImport::fromPayload($envelope->payload),
            "Assembling failed: {$exception->getMessage()}",
        );
    }
}
