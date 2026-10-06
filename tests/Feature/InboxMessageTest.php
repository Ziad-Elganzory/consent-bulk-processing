<?php

use App\Infrastructure\Messaging\Inbox\Models\InboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('claims a message once per consumer', function (): void {
    $messageId = fake()->uuid();

    expect(InboxMessage::claim('parser', $messageId))->toBeTrue()
        ->and(InboxMessage::claim('parser', $messageId))->toBeFalse()
        ->and(InboxMessage::claim('validator', $messageId))->toBeTrue();
});

it('leaves no claim behind when the handler transaction rolls back', function (): void {
    $messageId = fake()->uuid();

    try {
        DB::transaction(function () use ($messageId): void {
            InboxMessage::claim('parser', $messageId);

            throw new RuntimeException('handler failed');
        });
    } catch (RuntimeException) {
        // The handler failed, so the message will be retried.
    }

    expect(InboxMessage::claim('parser', $messageId))->toBeTrue();
});
