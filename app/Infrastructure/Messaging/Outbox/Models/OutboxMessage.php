<?php

namespace App\Infrastructure\Messaging\Outbox\Models;

use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['routing_key', 'payload', 'available_at'])]
class OutboxMessage extends Model
{
    use HasUuids;

    /**
     * Stores the envelope as a pending message, reusing its id as the row id.
     *
     * Call this inside the same transaction as the state change it announces.
     */
    public static function enqueue(MessageEnvelope $envelope): self
    {
        $message = new self([
            'routing_key' => $envelope->type(),
            'payload' => $envelope->toArray(),
        ]);

        $message->setAttribute($message->getKeyName(), $envelope->messageId);
        $message->save();

        return $message;
    }

    /**
     * Unpublished rows that used up their attempts and are no longer claimed.
     *
     * @param  Builder<OutboxMessage>  $query
     */
    #[Scope]
    protected function parked(Builder $query): void
    {
        $query->whereNull('published_at')
            ->where('attempt_count', '>=', config('bulk-imports.outbox.max_attempts'));
    }

    /**
     * Rebuilds the validated envelope from the stored payload.
     *
     * @throws \InvalidArgumentException when the stored payload is not a valid envelope
     */
    public function envelope(): MessageEnvelope
    {
        return MessageEnvelope::fromArray($this->payload, app(MessagingRegistry::class));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'locked_until' => 'datetime',
            'published_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }
}
