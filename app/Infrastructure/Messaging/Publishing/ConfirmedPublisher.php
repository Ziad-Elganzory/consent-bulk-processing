<?php

namespace App\Infrastructure\Messaging\Publishing;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Publishing\Exceptions\TransientPublishFailure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Publishes one message at a time on its own channel in confirm mode, and returns
 * only once the broker has stored it.
 */
class ConfirmedPublisher
{
    private const CONNECTION_TIMEOUT_SECONDS = 3;

    /** The channel already put into confirm mode, to spot when the connection hands out a new one. */
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly RabbitMQConnection $connection) {}

    /**
     * Publishes as mandatory, so an unroutable message fails instead of being dropped.
     *
     * @throws TransientPublishFailure when the broker is unreachable, times out, or nacks
     * @throws RuntimeException when the broker returns the message as unroutable
     */
    public function publish(AMQPMessage $message, string $exchange, string $routingKey): void
    {
        try {
            $channel = $this->channel();
            $channel->basic_publish($message, $exchange, $routingKey, true);

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

        // Short timeouts so a slow broker fails fast. Heartbeat is off because it would need longer ones.
        $channel = $this->connection->channel([
            'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            'read_timeout' => $ioTimeout,
            'write_timeout' => $ioTimeout,
            'heartbeat' => 0,
        ]);

        if ($channel === $this->channel) {
            return $channel;
        }

        $channel->confirm_select();

        $channel->set_nack_handler(static function (): void {
            throw new TransientPublishFailure('RabbitMQ negatively acknowledged a message.');
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
