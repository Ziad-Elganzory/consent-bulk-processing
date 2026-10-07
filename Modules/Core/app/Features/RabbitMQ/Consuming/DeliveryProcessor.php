<?php

namespace Modules\Core\Features\RabbitMQ\Consuming;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Contracts\MessagePublisher;
use Modules\Core\Features\RabbitMQ\Exceptions\InvalidEnvelope;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Topology\QueueDefinition;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Runs one delivery through its queue's handler and settles it with the broker:
 *
 *   handler succeeds           → ack
 *   handler throws, attempts left → copy to {queue}.retry with attempt + 1, then ack
 *   handler throws, last attempt  → copy to {queue}.dead with the error, call failed(), then ack
 *   body is not an envelope       → reject; the broker dead-letters it to {queue}.dead
 *
 * The copy is confirmed before the ack, so a crash in between redelivers the message
 * (a duplicate, which handlers tolerate) rather than losing it. If the broker cannot take
 * the copy, the exception propagates and the unacknowledged delivery is redelivered.
 */
class DeliveryProcessor
{
    public function __construct(
        private readonly Container $container,
        private readonly MessagePublisher $publisher,
    ) {}

    public function process(QueueDefinition $queue, AMQPMessage $delivery): DeliveryOutcome
    {
        try {
            $envelope = Envelope::fromAmqpMessage($delivery);
        } catch (InvalidEnvelope $exception) {
            Log::error($exception->getMessage(), ['queue' => $queue->name]);
            $delivery->reject(requeue: false);

            return DeliveryOutcome::Rejected;
        }

        /** @var MessageHandler $handler */
        $handler = $this->container->make($queue->handler);

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
        Envelope $envelope,
        AMQPMessage $delivery,
        Throwable $exception,
    ): DeliveryOutcome {
        $context = [
            'queue' => $queue->name,
            'message_id' => $envelope->messageId,
            'attempt' => $envelope->attempt,
            'max_attempts' => $queue->maxAttempts,
        ];

        if ($envelope->attempt < $queue->maxAttempts) {
            Log::warning("Message failed, retrying in {$queue->retryDelaySeconds}s: {$exception->getMessage()}", $context);
            $this->publisher->sendToQueue($queue->retryQueueName(), $envelope->forNextAttempt($exception->getMessage()));
            $delivery->ack();

            return DeliveryOutcome::Retried;
        }

        Log::error("Message used up its attempts, moved to {$queue->deadQueueName()}: {$exception->getMessage()}", $context);
        $this->publisher->sendToQueue($queue->deadQueueName(), $envelope->withFinalError($exception->getMessage()));

        try {
            $handler->failed($envelope, $exception);
        } catch (Throwable $failedHookException) {
            // The message is already safe in the dead queue; a broken hook must not redeliver it.
            report($failedHookException);
        }

        $delivery->ack();

        return DeliveryOutcome::DeadLettered;
    }
}
