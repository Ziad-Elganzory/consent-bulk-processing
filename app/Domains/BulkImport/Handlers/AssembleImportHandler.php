<?php

namespace App\Domains\BulkImport\Handlers;

use LogicException;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Placeholder until import assembly is built. See ValidateChunkHandler.
 */
final class AssembleImportHandler implements MessageHandler
{
    public function handle(Envelope $envelope): void
    {
        throw new LogicException('Import assembly is not built yet.');
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        //
    }
}
