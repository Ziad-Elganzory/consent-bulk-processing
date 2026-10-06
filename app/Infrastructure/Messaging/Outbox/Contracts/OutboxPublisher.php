<?php

namespace App\Infrastructure\Messaging\Outbox\Contracts;

use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;

interface OutboxPublisher
{
    /**
     * Publishes the message and returns only after the broker has confirmed it.
     *
     * @throws \RuntimeException when the broker rejects the message or cannot route it
     */
    public function publish(OutboxMessage $message): void;
}
