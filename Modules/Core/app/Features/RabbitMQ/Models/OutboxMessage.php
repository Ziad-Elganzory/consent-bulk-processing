<?php

namespace Modules\Core\Features\RabbitMQ\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;

/**
 * A message recorded in the same database transaction as the change it announces,
 * waiting for the outbox relay to publish it. Only Outbox::record() creates rows, from
 * the message's Envelope, so the row id and created_at are the message's own.
 *
 * @property string $id UUID v7, published as the message_id
 * @property string $exchange
 * @property string $routing_key
 * @property array<string, mixed> $payload
 * @property int $failed_attempts
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon|null $published_at
 */
#[Table(keyType: 'string', incrementing: false)]
#[Guarded(['*'])]
class OutboxMessage extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unpublished(Builder $query): void
    {
        $query->whereNull('published_at');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('published_at', '<', now()->subDays(MessagingConstants::OUTBOX_PRUNE_AFTER_DAYS));
    }

    public function toEnvelope(): Envelope
    {
        return new Envelope(
            messageId: $this->id,
            routingKey: $this->routing_key,
            payload: $this->payload,
            occurredAt: CarbonImmutable::instance($this->created_at),
        );
    }
}
