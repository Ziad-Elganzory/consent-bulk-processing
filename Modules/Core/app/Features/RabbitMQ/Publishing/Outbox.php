<?php

namespace Modules\Core\Features\RabbitMQ\Publishing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Contracts\Message;
use Modules\Core\Features\RabbitMQ\Contracts\MessagePublisher;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Models\OutboxMessage;
use Throwable;

/**
 * Transactional outbox: how modules publish.
 *
 *     DB::transaction(function () use ($outbox, $operation) {
 *         $operation->save();
 *         $outbox->record(new BulkOperationRequested($operation->id));
 *     });
 *
 * The message is stored with the change, so either both happen or neither; the relay
 * (rabbitmq:relay-outbox) then publishes stored messages in the order they were recorded.
 */
readonly class Outbox
{
    public function __construct(private MessagePublisher $publisher) {}

    public function record(Message $message): OutboxMessage
    {
        $envelope = Envelope::wrap($message);

        return OutboxMessage::forceCreate([
            'id' => $envelope->messageId,
            'exchange' => $message->exchange(),
            'routing_key' => $envelope->routingKey,
            'payload' => $envelope->payload,
            'created_at' => $envelope->occurredAt,
        ]);
    }

    /**
     * Publishes up to $limit unpublished messages, oldest first, and marks them published.
     * Stops at the first failure so later messages never overtake an earlier one.
     *
     * Rows are locked with SKIP LOCKED, so several relays can run without publishing a
     * message twice.
     *
     * @return int messages published
     */
    public function relayBatch(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            $messages = OutboxMessage::query()
                ->unpublished()
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = 0;

            foreach ($messages as $message) {
                try {
                    $this->publisher->publish($message->exchange, $message->toEnvelope());
                } catch (Throwable $exception) {
                    $message->forceFill([
                        'failed_attempts' => $message->failed_attempts + 1,
                        'last_error' => Str::limit($exception->getMessage(), MessagingConstants::ERROR_TEXT_MAX_LENGTH),
                    ])->save();

                    report($exception);

                    break;
                }

                $message->forceFill(['published_at' => now()])->save();
                $published++;
            }

            return $published;
        });
    }
}
