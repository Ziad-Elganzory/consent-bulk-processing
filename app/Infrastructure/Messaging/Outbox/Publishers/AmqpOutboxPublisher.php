<?php

namespace App\Infrastructure\Messaging\Outbox\Publishers;

use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use Illuminate\Support\Arr;
use LogicException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class AmqpOutboxPublisher implements OutboxPublisher
{
    private const CONNECTION_TIMEOUT_SECONDS = 3;

    private ?RabbitMQQueue $queue = null;

    private ?AMQPChannel $channel = null;

    public function __construct(private readonly RabbitMQConnector $connector) {}

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
                config('rabbitmq-topology.command_exchange'),
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
        if ($this->channel !== null && $this->queue?->getConnection()->isConnected()) {
            return $this->channel;
        }

        $this->disconnect();

        $queue = $this->connector->connect($this->connectionConfig());

        if (! $queue instanceof RabbitMQQueue) {
            throw new LogicException('The RabbitMQ connection did not return the expected queue driver.');
        }

        $channel = $queue->getChannel();
        $channel->confirm_select();

        $channel->set_nack_handler(static function (): void {
            throw new TransientPublishFailure('RabbitMQ negatively acknowledged an outbox message.');
        });

        $channel->set_return_listener(
            static function (int $replyCode, string $replyText, string $exchange, string $routingKey): void {
                throw new RuntimeException("RabbitMQ returned an unroutable message for [{$exchange}:{$routingKey}].");
            },
        );

        $this->queue = $queue;
        $this->channel = $channel;

        return $channel;
    }

    /**
     * The package connection settings, with timeouts sized for publishing.
     *
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        $config = config('queue.connections.rabbitmq');
        $ioTimeout = config('bulk-imports.outbox.publish_timeout_seconds') + 2;

        Arr::set($config, 'options.connection_timeout', self::CONNECTION_TIMEOUT_SECONDS);
        Arr::set($config, 'options.read_timeout', $ioTimeout);
        Arr::set($config, 'options.write_timeout', $ioTimeout);

        return $config;
    }

    private function disconnect(): void
    {
        try {
            $this->queue?->close();
        } catch (Throwable) {
            // The connection is already broken, so there is nothing left to close.
        }

        $this->queue = null;
        $this->channel = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
