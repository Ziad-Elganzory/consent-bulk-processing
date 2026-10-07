<?php

namespace App\Domains\BulkImport\Handlers;

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Services\ImportParser;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Throwable;

/**
 * Connects the parse queue to the parser: reads the message and hands it over.
 */
final class ParseImportHandler implements MessageHandler
{
    public function __construct(private readonly ImportParser $parser) {}

    public function handle(Envelope $envelope): void
    {
        $this->parser->parse(ParseRequested::fromPayload($envelope->payload));
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        $this->parser->fail(
            ParseRequested::fromPayload($envelope->payload),
            "Parsing failed: {$exception->getMessage()}",
        );
    }
}
