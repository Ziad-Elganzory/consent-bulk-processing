<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Connection\RabbitMQConnection;
use App\Infrastructure\Messaging\Consuming\DeliveryProcessor;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Long-running worker for one queue. Everything about the queue (handler, prefetch,
 * attempts, retry delay) comes from its QueueDefinition.
 *
 * Stops after the message in progress on SIGTERM, SIGINT or SIGQUIT, or when a limit is
 * reached, so a process manager can restart it fresh.
 */
#[Signature('rabbitmq:consume
    {queue : Queue name, as declared by a module}
    {--max-messages=0 : Stop after this many messages (0 = no limit)}
    {--max-time=0 : Stop after this many seconds (0 = no limit)}
    {--memory=128 : Stop when memory use exceeds this many megabytes}')]
#[Description('Consume a declared queue, running each message through its handler.')]
class ConsumeQueue extends Command
{
    private const int WAIT_TIMEOUT_SECONDS = 1;

    private bool $stopRequested = false;

    private int $processedMessages = 0;

    public function handle(MessagingRegistry $registry, RabbitMQConnection $connection, DeliveryProcessor $processor): int
    {
        try {
            $queue = $registry->queue($this->argument('queue'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($queue->handler === null) {
            $this->components->error("Queue [{$queue->name}] has no handler yet.");

            return self::FAILURE;
        }

        // The command object can be reused within one process (Artisan::call), so each run starts fresh.
        $this->stopRequested = false;
        $this->processedMessages = 0;

        $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
            $this->stopRequested = true;
        });

        $channel = $connection->channel();
        $channel->basic_qos(0, $queue->prefetch, false);

        // Keeps the connection alive while a slow handler blocks the process.
        $amqpConnection = $channel->getConnection();
        $heartbeatSender = $amqpConnection->getHeartbeat() > 0 ? new PCNTLHeartbeatSender($amqpConnection) : null;
        $heartbeatSender?->register();

        $channel->basic_consume($queue->name, callback: function (AMQPMessage $delivery) use ($queue, $processor): void {
            $outcome = $processor->process($queue, $delivery);
            $this->processedMessages++;
            $this->line(sprintf('%s  %s  %s', now()->toDateTimeString(), $delivery->has('message_id') ? $delivery->get('message_id') : '-', $outcome->value));
        });

        $this->components->info("Consuming {$queue->name} (prefetch {$queue->prefetch}, {$queue->maxAttempts} attempts).");
        $startedAt = time();

        try {
            while ($channel->is_consuming() && ! $this->shouldStop($startedAt)) {
                try {
                    $channel->wait(timeout: self::WAIT_TIMEOUT_SECONDS);
                } catch (AMQPTimeoutException) {
                    // No delivery within the timeout: loop to re-check the stop conditions.
                }
            }
        } finally {
            $heartbeatSender?->unregister();
            $connection->close();
        }

        return self::SUCCESS;
    }

    private function shouldStop(int $startedAt): bool
    {
        $maxMessages = (int) $this->option('max-messages');
        $maxSeconds = (int) $this->option('max-time');

        return $this->stopRequested
            || ($maxMessages > 0 && $this->processedMessages >= $maxMessages)
            || ($maxSeconds > 0 && time() - $startedAt >= $maxSeconds)
            || memory_get_usage(true) >= (int) $this->option('memory') * 1024 * 1024;
    }
}
