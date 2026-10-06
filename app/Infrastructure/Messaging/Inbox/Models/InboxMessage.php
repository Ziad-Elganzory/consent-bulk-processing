<?php

namespace App\Infrastructure\Messaging\Inbox\Models;

use Database\Factories\Infrastructure\Messaging\Inbox\Models\InboxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A message one consumer has already handled.
 */
#[Fillable(['consumer_name', 'message_id'])]
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Records that $consumer handled $messageId. Call it inside the transaction that
     * commits the handler's result, so a failed handler leaves no claim behind.
     *
     * @return bool false when the message was already handled, so the handler should skip it
     */
    public static function claim(string $consumer, string $messageId): bool
    {
        return static::query()->insertOrIgnore([
            'consumer_name' => $consumer,
            'message_id' => $messageId,
            'created_at' => now(),
        ]) === 1;
    }

    protected static function newFactory(): Factory
    {
        return InboxMessageFactory::new();
    }
}
