<?php

namespace Modules\Core\Features\RabbitMQ\Exceptions;

use LogicException;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;

/**
 * A module's ModuleMessaging declares something inconsistent: a bug, caught by the
 * MessagingRegistry when it loads (and by the tests), never at message time.
 */
class InvalidMessagingDefinition extends LogicException
{
    public static function queueWithoutRoutingKeys(string $queue): self
    {
        return new self("Queue [{$queue}] has no routing keys, so it would never receive a message.");
    }

    public static function queueLimitBelowOne(string $queue): self
    {
        return new self("Queue [{$queue}]: max attempts, retry delay and prefetch must each be at least 1.");
    }

    public static function duplicateExchange(string $exchange, string $firstOwner, string $secondOwner): self
    {
        return new self("Exchange [{$exchange}] is declared by both [{$firstOwner}] and [{$secondOwner}].");
    }

    public static function duplicateQueue(string $queue, string $firstOwner, string $secondOwner): self
    {
        return new self("Queue [{$queue}] is declared by both [{$firstOwner}] and [{$secondOwner}].");
    }

    public static function undeclaredExchange(string $queue, string $exchange): self
    {
        return new self("Queue [{$queue}] binds to exchange [{$exchange}], which no module declares.");
    }

    public static function invalidHandler(string $queue, string $handler): self
    {
        return new self(sprintf('Queue [%s]: handler [%s] does not exist or does not implement %s.', $queue, $handler, MessageHandler::class));
    }

    public static function unknownQueue(string $queue): self
    {
        return new self("No module declares queue [{$queue}]; run rabbitmq:topology to list the declared queues.");
    }
}
