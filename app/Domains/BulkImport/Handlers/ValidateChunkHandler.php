<?php

namespace App\Domains\BulkImport\Handlers;

use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Services\ChunkValidator;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Connects the validate queue to the validator: reads the message and hands it over.
 */
final class ValidateChunkHandler implements MessageHandler
{
    public function __construct(private readonly ChunkValidator $validator) {}

    public function handle(Envelope $envelope): void
    {
        $this->validator->validate(ValidateChunk::fromPayload($envelope->payload));
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        $this->validator->fail(
            ValidateChunk::fromPayload($envelope->payload),
            "Validation failed: {$exception->getMessage()}",
        );
    }
}
