<?php

namespace Modules\Core\Features\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The broker did not confirm a message, so it must be treated as not sent.
 */
class MessageNotPublished extends RuntimeException
{
    public static function rejectedByBroker(string $messageId): self
    {
        return new self("The broker rejected message [{$messageId}].");
    }

    public static function unroutable(string $messageId, string $queue): self
    {
        return new self("Message [{$messageId}] could not be routed to queue [{$queue}]; run rabbitmq:declare-topology.");
    }

    public static function notConfirmed(string $messageId, Throwable $previous): self
    {
        return new self("The broker did not confirm message [{$messageId}]: {$previous->getMessage()}", previous: $previous);
    }
}
