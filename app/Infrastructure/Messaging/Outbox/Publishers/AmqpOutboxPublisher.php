<?php

namespace App\Infrastructure\Messaging\Outbox\Publishers;

use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use App\Infrastructure\Messaging\Publishing\ConfirmedPublisher;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

class AmqpOutboxPublisher implements OutboxPublisher
{
    public function __construct(
        private readonly ConfirmedPublisher $publisher,
        private readonly MessagingRegistry $registry,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        // Validate first, so a malformed row fails before any connection is opened.
        $envelope = $message->envelope();

        if ($envelope->type() !== $message->routing_key) {
            throw new RuntimeException(
                "Message {$message->getKey()} has routing key [{$message->routing_key}] but type [{$envelope->type()}].",
            );
        }

        $this->publisher->publish(
            new AMQPMessage($envelope->toJson(), [
                'message_id' => $envelope->messageId,
                'correlation_id' => $envelope->correlationId,
                'type' => $envelope->type(),
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]),
            $this->registry->exchangeFor($envelope->type()),
            $envelope->type(),
        );
    }
}
