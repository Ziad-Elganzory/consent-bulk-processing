<?php

namespace App\Domains\BulkImport\Handlers;

use LogicException;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Placeholder until chunk validation is built. The SDK requires every queue to name a
 * handler, so the validate queue can exist and keep its messages safe, but nothing runs
 * this class: start no consumer for the validate queue until it is replaced.
 */
final class ValidateChunkHandler implements MessageHandler
{
    public function handle(Envelope $envelope): void
    {
        throw new LogicException('Chunk validation is not built yet.');
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        //
    }
}
