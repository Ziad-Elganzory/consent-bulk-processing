<?php

namespace Modules\Core\Features\RabbitMQ\Publishing;

use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Contracts\MessagePublisher;
use Modules\Core\Features\RabbitMQ\Exceptions\MessageNotPublished;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Publishes over its own channel in confirm mode, one message at a time: every call waits
 * for the broker's ack, so a returned call means the message is stored.
 */
class AmqpMessagePublisher implements MessagePublisher
{
    private ?AMQPChannel $channel = null;

    private bool $rejectedByBroker = false;

    private bool $returnedAsUnroutable = false;

    public function __construct(private readonly RabbitMQConnection $connection) {}

    public function publish(string $exchange, Envelope $envelope): void
    {
        $this->publishAndConfirm($envelope, $exchange, $envelope->routingKey, mandatory: false);
    }

    public function sendToQueue(string $queue, Envelope $envelope): void
    {
        // mandatory: the broker returns the message instead of dropping it when the queue is missing.
        $this->publishAndConfirm($envelope, '', $queue, mandatory: true);

        if ($this->returnedAsUnroutable) {
            throw MessageNotPublished::unroutable($envelope->messageId, $queue);
        }
    }

    private function publishAndConfirm(Envelope $envelope, string $exchange, string $routingKey, bool $mandatory): void
    {
        $channel = $this->confirmChannel();
        $this->rejectedByBroker = false;
        $this->returnedAsUnroutable = false;

        $channel->basic_publish($envelope->toAmqpMessage(), $exchange, $routingKey, $mandatory);

        try {
            $channel->wait_for_pending_acks_returns(MessagingConstants::PUBLISH_CONFIRM_TIMEOUT);
        } catch (AMQPTimeoutException $exception) {
            // The channel may still deliver the late confirm; start the next message on a fresh one.
            $this->channel = null;

            throw MessageNotPublished::notConfirmed($envelope->messageId, $exception);
        }

        if ($this->rejectedByBroker) {
            throw MessageNotPublished::rejectedByBroker($envelope->messageId);
        }
    }

    private function confirmChannel(): AMQPChannel
    {
        if ($this->channel?->is_open()) {
            return $this->channel;
        }

        $this->channel = $this->connection->openChannel();
        $this->channel->set_nack_handler(function (): void {
            $this->rejectedByBroker = true;
        });
        $this->channel->set_return_listener(function (): void {
            $this->returnedAsUnroutable = true;
        });
        $this->channel->confirm_select();

        return $this->channel;
    }
}
