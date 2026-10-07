<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Consuming\DeliveryProcessor;
use Modules\Core\Features\RabbitMQ\Exceptions\InvalidMessagingDefinition;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Long-running worker for one queue; everything about the queue (handler, prefetch,
 * attempts, retry delay) comes from its QueueDefinition.
 *
 * Stops after the message in progress on SIGTERM/SIGINT/SIGQUIT, or when a limit is
 * reached, so a process manager (Supervisor) can restart it fresh.
 */
class ConsumeQueue extends Command
{
    private const int BYTES_PER_MEGABYTE = 1024 * 1024;

    protected $signature = 'rabbitmq:consume
        {queue : Queue name, as listed by rabbitmq:topology}
        {--max-messages=0 : Stop after this many messages (0 = no limit)}
        {--max-time=0 : Stop after this many seconds (0 = no limit)}
        {--memory=128 : Stop when memory use exceeds this many megabytes}';

    protected $description = 'Consume a declared queue, running each message through its handler';

    private bool $stopRequested = false;

    private int $processedMessages = 0;

    public function handle(MessagingRegistry $registry, RabbitMQConnection $connection, DeliveryProcessor $processor): int
    {
        try {
            $queue = $registry->queue($this->argument('queue'));
        } catch (InvalidMessagingDefinition $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // The command object can be reused within one process (Artisan::call), so each run starts fresh.
        $this->stopRequested = false;
        $this->processedMessages = 0;

        $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
            $this->stopRequested = true;
        });

        $channel = $connection->openChannel();
        $channel->basic_qos(0, $queue->prefetch, false);

        // Keeps the connection alive while a slow handler blocks the process.
        $heartbeatSender = $connection->heartbeatSeconds() > 0
            ? new PCNTLHeartbeatSender($connection->connection())
            : null;
        $heartbeatSender?->register();

        $channel->basic_consume($queue->name, callback: function (AMQPMessage $delivery) use ($queue, $processor): void {
            $outcome = $processor->process($queue, $delivery);
            $this->processedMessages++;
            $this->line(sprintf('%s  %s  %s', now()->toDateTimeString(), $delivery->has('message_id') ? $delivery->get('message_id') : '-', $outcome->value));
        });

        $this->components->info("Consuming {$queue->name} (prefetch {$queue->prefetch}, {$queue->maxAttempts} attempts).");
        $startedAt = time();

        while ($channel->is_consuming() && ! $this->shouldStop($startedAt)) {
            try {
                $channel->wait(timeout: MessagingConstants::CONSUMER_WAIT_TIMEOUT);
            } catch (AMQPTimeoutException) {
                // No delivery within the timeout: loop to re-check the stop conditions.
            }
        }

        $heartbeatSender?->unregister();
        $connection->close();

        return self::SUCCESS;
    }

    private function shouldStop(int $startedAt): bool
    {
        $maxMessages = (int) $this->option('max-messages');
        $maxSeconds = (int) $this->option('max-time');

        return $this->stopRequested
            || ($maxMessages > 0 && $this->processedMessages >= $maxMessages)
            || ($maxSeconds > 0 && time() - $startedAt >= $maxSeconds)
            || memory_get_usage(true) >= (int) $this->option('memory') * self::BYTES_PER_MEGABYTE;
    }
}
