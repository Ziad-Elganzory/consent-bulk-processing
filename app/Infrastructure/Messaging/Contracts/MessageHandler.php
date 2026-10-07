<?php

namespace App\Infrastructure\Messaging\Contracts;

use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use Throwable;

/**
 * The domain code that runs for each message of one queue. A fresh instance is created
 * through the container for every message.
 *
 * The same message can arrive more than once, so handle() must leave the same result
 * when it runs again for a message id it has already seen.
 */
interface MessageHandler
{
    /**
     * Return to mark the message done. Throw to have it tried again later.
     */
    public function handle(MessageEnvelope $envelope): void;

    /**
     * Runs once, after the message failed on its final attempt.
     */
    public function failed(MessageEnvelope $envelope, Throwable $exception): void;
}
