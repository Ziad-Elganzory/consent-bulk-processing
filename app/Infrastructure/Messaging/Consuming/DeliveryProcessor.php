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
 * Runs one delivery through its queue's handler and settles it with the broker:
 *
 *   body is not a valid envelope    → reject; the broker dead-letters it to {queue}.dead
 *   handler succeeds                → ack
 *   handler throws, attempts left   → confirmed copy to {queue}.retry with attempt + 1, then ack
 *   handler throws, last attempt    → confirmed copy to {queue}.dead, call failed(), then ack
 *
 * The copy is confirmed before the ack, so a crash in between redelivers the message
 * (a duplicate, which handlers tolerate) rather than losing it. If the broker cannot
 * take the copy, the exception propagates and the unacknowledged delivery is redelivered.
 */
class DeliveryProcessor
{
    public const string ATTEMPT_HEADER = 'x-attempt';

    public const string ERROR_HEADER = 'x-last-error';

    private const int ERROR_MAX_LENGTH = 1000;

    public function __construct(
        private readonly Container $container,
        private readonly ConfirmedPublisher $publisher,
        private readonly MessagingRegistry $registry,
    ) {}

    public function process(QueueDefinition $queue, AMQPMessage $delivery): DeliveryOutcome
    {
        try {
            $envelope = MessageEnvelope::fromJson($delivery->getBody(), $this->registry);
        } catch (InvalidArgumentException|JsonException $exception) {
            Log::error("Rejected a message that is not a valid envelope: {$exception->getMessage()}", ['queue' => $queue->name]);
            $delivery->reject(requeue: false);

            return DeliveryOutcome::Rejected;
        }

        /** @var MessageHandler $handler */
        $handler = $this->container->make(
            $queue->handler ?? throw new LogicException("Queue [{$queue->name}] has no handler."),
        );

        try {
            $handler->handle($envelope);
        } catch (Throwable $exception) {
            return $this->settleFailure($queue, $handler, $envelope, $delivery, $exception);
        }

        $delivery->ack();

        return DeliveryOutcome::Handled;
    }

    private function settleFailure(
        QueueDefinition $queue,
        MessageHandler $handler,
        MessageEnvelope $envelope,
        AMQPMessage $delivery,
        Throwable $exception,
    ): DeliveryOutcome {
        $attempt = (int) ($this->headers($delivery)[self::ATTEMPT_HEADER] ?? 1);
        $context = ['queue' => $queue->name, 'message_id' => $envelope->messageId, 'attempt' => $attempt];

        if ($attempt < $queue->maxAttempts) {
            Log::warning("Message failed, retrying in {$queue->retryDelaySeconds}s: {$exception->getMessage()}", $context);
            $this->publisher->publish($this->copy($delivery, $attempt + 1, $exception), '', $queue->retryQueueName());
            $delivery->ack();

            return DeliveryOutcome::Retried;
        }

        Log::error("Message used up its attempts, moved to {$queue->deadQueueName()}: {$exception->getMessage()}", $context);
        $this->publisher->publish($this->copy($delivery, $attempt, $exception), '', $queue->deadQueueName());

        try {
            $handler->failed($envelope, $exception);
        } catch (Throwable $failedHookException) {
            // The message is already safe in the dead queue; a broken hook must not redeliver it.
            report($failedHookException);
        }

        $delivery->ack();

        return DeliveryOutcome::DeadLettered;
    }

    /**
     * The delivery with its body and properties unchanged, and the attempt headers updated.
     */
    private function copy(AMQPMessage $delivery, int $attempt, Throwable $exception): AMQPMessage
    {
        $properties = $delivery->get_properties();
        $properties['application_headers'] = new AMQPTable([
            ...$this->headers($delivery),
            self::ATTEMPT_HEADER => $attempt,
            self::ERROR_HEADER => Str::limit($exception->getMessage(), self::ERROR_MAX_LENGTH),
        ]);

        return new AMQPMessage($delivery->getBody(), $properties);
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(AMQPMessage $delivery): array
    {
        $headers = $delivery->get_properties()['application_headers'] ?? null;

        return $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    }
}
