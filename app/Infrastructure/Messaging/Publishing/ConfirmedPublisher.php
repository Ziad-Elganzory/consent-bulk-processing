<?php

namespace App\Infrastructure\Messaging\Publishing;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Publishing\Exceptions\TransientPublishFailure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Sends one message at a time and returns only once RabbitMQ has stored it. Messages are
 * published as mandatory, so one that no queue would receive comes back instead of being
 * dropped.
 */
class ConfirmedPublisher
{
    private const int CONNECTION_TIMEOUT_SECONDS = 3;

    /** Our confirm-mode channel, so a new one handed out after a reconnect is set up again. */
    private ?AMQPChannel $channel = null;

    /** Set by the nack listener: the broker refused to store the message. */
    private bool $refused = false;

    /** Set by the return listener: no queue matched the routing key. */
    private bool $unroutable = false;

    public function __construct(private readonly RabbitMQConnection $connection) {}

    /**
     * @throws TransientPublishFailure when RabbitMQ is unreachable, too slow, or refuses the message
     * @throws RuntimeException when no queue matches the routing key
     */
    public function publish(AMQPMessage $message, string $exchange, string $routingKey): void
    {
        $this->refused = false;
        $this->unroutable = false;

        try {
            $channel = $this->channel();
            $channel->basic_publish($message, $exchange, $routingKey, mandatory: true);

            // Blocks until RabbitMQ acks, nacks or returns the message, or the timeout passes.
            $channel->wait_for_pending_acks_returns(config('bulk-imports.outbox.publish_timeout_seconds'));
        } catch (AMQPExceptionInterface $exception) {
            $this->reset();

            throw new TransientPublishFailure("RabbitMQ unavailable: {$exception->getMessage()}", previous: $exception);
        }

        if ($this->unroutable) {
            throw new RuntimeException("No queue receives routing key [{$routingKey}] on exchange [{$exchange}].");
        }

        if ($this->refused) {
            $this->reset();

            throw new TransientPublishFailure("RabbitMQ refused to store the message for [{$routingKey}].");
        }
    }

    private function channel(): AMQPChannel
    {
        $ioTimeout = config('bulk-imports.outbox.publish_timeout_seconds') + 2;

        // Short timeouts so a slow broker fails fast. Heartbeat stays off, because it would
        // need timeouts of at least twice its interval.
        $channel = $this->connection->channel([
            'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            'read_timeout' => $ioTimeout,
            'write_timeout' => $ioTimeout,
            'heartbeat' => 0,
        ]);

        if ($channel !== $this->channel) {
            $channel->confirm_select();
            $channel->set_nack_handler(function (): void {
                $this->refused = true;
            });
            $channel->set_return_listener(function (): void {
                $this->unroutable = true;
            });

            $this->channel = $channel;
        }

        return $channel;
    }

    /**
     * Drops the connection so the next publish starts on a clean one.
     */
    private function reset(): void
    {
        $this->connection->close();
        $this->channel = null;
    }

    public function __destruct()
    {
        $this->reset();
    }
}
