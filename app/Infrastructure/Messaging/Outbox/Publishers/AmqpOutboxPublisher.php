<?php

namespace App\Infrastructure\Messaging\Outbox\Publishers;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

class AmqpOutboxPublisher implements OutboxPublisher
{
    private const CONNECTION_TIMEOUT_SECONDS = 3;

    /** The channel already put into confirm mode, to spot when the connection hands out a new one. */
    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly RabbitMQConnection $connection,
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

        try {
            $channel = $this->channel();

            $amqpMessage = new AMQPMessage(
                $envelope->toJson(),
                [
                    'message_id' => $envelope->messageId,
                    'correlation_id' => $envelope->correlationId,
                    'type' => $envelope->type(),
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ],
            );

            // Mandatory, so an unroutable message is returned instead of silently dropped.
            $channel->basic_publish(
                $amqpMessage,
                $this->registry->exchangeFor($envelope->type()),
                $envelope->type(),
                true,
            );

            // The nack and return handlers throw from inside this wait.
            $channel->wait_for_pending_acks_returns(config('bulk-imports.outbox.publish_timeout_seconds'));
        } catch (AMQPExceptionInterface $exception) {
            // The channel state is unknown after a broker error, so start fresh next time.
            $this->disconnect();

            throw new TransientPublishFailure("RabbitMQ unavailable: {$exception->getMessage()}", previous: $exception);
        } catch (TransientPublishFailure $exception) {
            $this->disconnect();

            throw $exception;
        }
    }

    private function channel(): AMQPChannel
    {
        $ioTimeout = config('bulk-imports.outbox.publish_timeout_seconds') + 2;

        $channel = $this->connection->channel([
            'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            'read_timeout' => $ioTimeout,
            'write_timeout' => $ioTimeout,
        ]);

        if ($channel === $this->channel) {
            return $channel;
        }

        $channel->confirm_select();

        $channel->set_nack_handler(static function (): void {
            throw new TransientPublishFailure('RabbitMQ negatively acknowledged an outbox message.');
        });

        $channel->set_return_listener(
            static function (int $replyCode, string $replyText, string $exchange, string $routingKey): void {
                throw new RuntimeException("RabbitMQ returned an unroutable message for [{$exchange}:{$routingKey}].");
            },
        );

        return $this->channel = $channel;
    }

    private function disconnect(): void
    {
        $this->connection->close();
        $this->channel = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
