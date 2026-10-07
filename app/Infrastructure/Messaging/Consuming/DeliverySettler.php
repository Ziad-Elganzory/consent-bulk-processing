<?php

namespace App\Infrastructure\Messaging\Consuming;

use App\Infrastructure\Messaging\Contracts\MessageHandler;
use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Publishing\ConfirmedPublisher;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use App\Infrastructure\Messaging\Topology\QueueDefinition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Decides what happens to one message taken from a queue, and tells RabbitMQ.
 *
 * A body that is not a valid envelope is rejected, and the work queue's dead-letter setting
 * moves it to the dead queue. Otherwise the queue's handler runs. If it returns, the message
 * is acknowledged. If it throws, a copy carrying the next attempt number goes to the retry
 * queue, or after the final attempt to the dead queue, where the handler's failed() runs.
 *
 * The copy is always stored (confirmed by RabbitMQ) before the original is acknowledged.
 * If the copy cannot be stored, the exception escapes, the original stays unacknowledged,
 * and RabbitMQ delivers it again. A crash in between can only produce a duplicate.
 */
class DeliverySettler
{
    /** Which delivery of the message this is, starting at 1. */
    public const string ATTEMPT_HEADER = 'x-delivery-attempt';

    /** Why the previous attempt failed. */
    public const string FAILURE_HEADER = 'x-failure-reason';

    private const int FAILURE_REASON_MAX_LENGTH = 1000;

    public function __construct(
        private readonly Container $container,
        private readonly ConfirmedPublisher $publisher,
        private readonly MessagingRegistry $registry,
    ) {}

    public function settle(QueueDefinition $queue, AMQPMessage $delivery): Settlement
    {
        try {
            $envelope = MessageEnvelope::fromJson($delivery->getBody(), $this->registry);
        } catch (InvalidArgumentException|JsonException $exception) {
            Log::warning("Unreadable message rejected from {$queue->name}: {$exception->getMessage()}");
            $delivery->reject(requeue: false);

            return Settlement::Unreadable;
        }

        $handler = $this->handlerFor($queue);

        try {
            $handler->handle($envelope);
        } catch (Throwable $failure) {
            return $this->settleFailure($queue, $handler, $envelope, $delivery, $failure);
        }

        $delivery->ack();

        return Settlement::Acknowledged;
    }

    private function handlerFor(QueueDefinition $queue): MessageHandler
    {
        if ($queue->handler === null) {
            throw new LogicException("Queue [{$queue->name}] has no handler.");
        }

        return $this->container->make($queue->handler);
    }

    private function settleFailure(
        QueueDefinition $queue,
        MessageHandler $handler,
        MessageEnvelope $envelope,
        AMQPMessage $delivery,
        Throwable $failure,
    ): Settlement {
        $attempt = $this->attemptOf($delivery);
        $log = ['queue' => $queue->name, 'message_id' => $envelope->messageId, 'attempt' => $attempt];

        if ($attempt < $queue->maxAttempts) {
            $this->publisher->publish($this->copyOf($delivery, $attempt + 1, $failure), '', $queue->retryQueue());
            $delivery->ack();
            Log::warning("Attempt {$attempt} failed, retrying in {$queue->retryDelaySeconds}s: {$failure->getMessage()}", $log);

            return Settlement::RetryScheduled;
        }

        $this->publisher->publish($this->copyOf($delivery, $attempt, $failure), '', $queue->deadQueue());

        try {
            $handler->failed($envelope, $failure);
        } catch (Throwable $hookFailure) {
            // The copy is already in the dead queue, so this must not cause a redelivery.
            report($hookFailure);
        }

        $delivery->ack();
        Log::error("Final attempt failed, moved to {$queue->deadQueue()}: {$failure->getMessage()}", $log);

        return Settlement::MovedToDeadQueue;
    }

    private function attemptOf(AMQPMessage $delivery): int
    {
        return (int) ($this->headersOf($delivery)[self::ATTEMPT_HEADER] ?? 1);
    }

    /**
     * Same body and properties as the delivery, with the attempt headers replaced.
     */
    private function copyOf(AMQPMessage $delivery, int $attempt, Throwable $failure): AMQPMessage
    {
        $properties = $delivery->get_properties();
        $properties['application_headers'] = new AMQPTable([
            ...$this->headersOf($delivery),
            self::ATTEMPT_HEADER => $attempt,
            self::FAILURE_HEADER => Str::limit($failure->getMessage(), self::FAILURE_REASON_MAX_LENGTH),
        ]);

        return new AMQPMessage($delivery->getBody(), $properties);
    }

    /**
     * @return array<string, mixed>
     */
    private function headersOf(AMQPMessage $delivery): array
    {
        $headers = $delivery->get_properties()['application_headers'] ?? null;

        return $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    }
}
