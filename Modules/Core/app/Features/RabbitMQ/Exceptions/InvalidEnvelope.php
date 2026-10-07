<?php

namespace Modules\Core\Features\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A delivered message is not a valid envelope (not JSON, or missing fields). It can never
 * succeed, so the consumer rejects it straight to the dead queue instead of retrying.
 */
class InvalidEnvelope extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("Invalid message envelope: {$reason}", previous: $previous);
    }
}
