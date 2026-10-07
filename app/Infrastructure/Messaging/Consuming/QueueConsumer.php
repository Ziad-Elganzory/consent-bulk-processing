<?php

namespace App\Infrastructure\Messaging\Consuming;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Topology\QueueDefinition;
use Closure;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Takes messages from one queue and passes each to the DeliverySettler, until a limit is
 * reached or stop() is called. Returning always happens between messages, never mid-way.
 */
class QueueConsumer
{
    /** How often the loop wakes up to check whether it should stop. */
    private const int POLL_SECONDS = 1;

    private bool $stopRequested = false;

    public function __construct(
        private readonly RabbitMQConnection $connection,
        private readonly DeliverySettler $settler,
    ) {}

    /**
     * Asks run() to return after the message in hand. Safe to call from a signal handler.
     */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * @param  Closure(AMQPMessage, Settlement): void  $afterEach  called after every settled message
     */
    public function run(QueueDefinition $queue, ConsumerLimits $limits, Closure $afterEach): void
    {
        $this->stopRequested = false;
        $settled = 0;
        $startedAt = time();

        $channel = $this->connection->channel();
        $channel->basic_qos(0, $queue->prefetch, false);

        // While a handler is busy nothing reads from the socket, so the broker's heartbeats
        // would go unanswered. A signal-driven sender keeps them going.
        $amqpConnection = $channel->getConnection();
        $heartbeats = $amqpConnection->getHeartbeat() > 0 ? new PCNTLHeartbeatSender($amqpConnection) : null;
        $heartbeats?->register();

        $channel->basic_consume($queue->name, callback: function (AMQPMessage $delivery) use ($queue, $afterEach, &$settled): void {
            $afterEach($delivery, $this->settler->settle($queue, $delivery));
            $settled++;
        });

        try {
            while ($channel->is_consuming() && ! $this->stopRequested && ! $limits->reached($settled, time() - $startedAt)) {
                try {
                    $channel->wait(timeout: self::POLL_SECONDS);
                } catch (AMQPTimeoutException) {
                    // Nothing arrived; loop round to check the stop conditions again.
                }
            }
        } finally {
            $heartbeats?->unregister();
            $this->connection->close();
        }
    }
}
