<?php

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use App\Infrastructure\Messaging\Outbox\Services\OutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeOutboxPublisher(bool $fail = false, ?Closure $handler = null): object
{
    $fake = new class($fail, $handler) implements OutboxPublisher
    {
        /** @var list<string> */
        public array $published = [];

        /** @var list<string> */
        public array $attempted = [];

        /** @var list<int> seconds each row was leased for when it was published */
        public array $leases = [];

        public function __construct(public bool $fail, public ?Closure $handler) {}

        public function publish(OutboxMessage $message): void
        {
            $this->attempted[] = $message->getKey();
            $this->leases[] = (int) round(now()->diffInSeconds($message->locked_until, false));

            if ($this->fail) {
                throw new RuntimeException('broker down');
            }

            if ($this->handler !== null) {
                ($this->handler)($message);
            }

            $this->published[] = $message->getKey();
        }
    };

    app()->instance(OutboxPublisher::class, $fake);

    return $fake;
}

function pendingOutboxMessage(array $attributes = []): OutboxMessage
{
    $message = OutboxMessage::create([
        'routing_key' => ParseRequested::type(),
        'payload' => ['bulk_import_id' => 'abc'],
    ]);

    $message->forceFill($attributes)->save();

    return $message->refresh();
}

it('publishes due messages and marks them published', function (): void {
    $fake = fakeOutboxPublisher();
    $message = pendingOutboxMessage();

    $this->artisan('outbox:relay --once')->assertSuccessful();

    $message->refresh();

    expect($fake->published)->toBe([$message->getKey()])
        ->and($message->published_at)->not->toBeNull()
        ->and($message->locked_until)->toBeNull()
        ->and($message->attempt_count)->toBe(1);
});

it('does not publish a message twice', function (): void {
    $fake = fakeOutboxPublisher();
    pendingOutboxMessage();

    $this->artisan('outbox:relay --once');
    $this->artisan('outbox:relay --once');

    expect($fake->published)->toHaveCount(1);
});

it('skips messages with an active lease', function (): void {
    $fake = fakeOutboxPublisher();
    pendingOutboxMessage(['locked_until' => now()->addMinute()]);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([]);
});

it('picks up messages whose lease has expired', function (): void {
    $fake = fakeOutboxPublisher();
    $message = pendingOutboxMessage(['locked_until' => now()->subMinute()]);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([$message->getKey()]);
});

it('skips messages that are not yet available', function (): void {
    $fake = fakeOutboxPublisher();
    pendingOutboxMessage(['available_at' => now()->addMinutes(5)]);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([]);
});

it('records the error and backs off when publishing fails', function (): void {
    fakeOutboxPublisher(fail: true);
    $message = pendingOutboxMessage();

    $this->artisan('outbox:relay --once')->assertSuccessful();

    $message->refresh();

    expect($message->published_at)->toBeNull()
        ->and($message->last_error)->toBe('broker down')
        ->and($message->locked_until)->toBeNull()
        ->and($message->available_at->isFuture())->toBeTrue();
});

it('stops retrying after the maximum number of attempts', function (): void {
    $fake = fakeOutboxPublisher();
    pendingOutboxMessage(['attempt_count' => config('bulk-imports.outbox.max_attempts')]);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([]);
});

it('leases a row for long enough to publish a whole batch', function (): void {
    $fake = fakeOutboxPublisher();
    pendingOutboxMessage();

    $this->artisan('outbox:relay --once');

    $expected = app(OutboxRelay::class)->leaseSeconds();

    expect($expected)->toBe(
        config('bulk-imports.outbox.batch_size') * config('bulk-imports.outbox.publish_timeout_seconds')
        + config('bulk-imports.outbox.lease_buffer_seconds'),
    )->and($fake->leases[0])->toBeBetween($expected - 2, $expected);
});

it('does not use up attempts when the broker is unavailable', function (): void {
    $fake = fakeOutboxPublisher(handler: function (): void {
        throw new TransientPublishFailure('RabbitMQ unavailable');
    });
    $message = pendingOutboxMessage();

    $this->artisan('outbox:relay --once')->assertSuccessful();

    $message->refresh();

    expect($fake->published)->toBe([])
        ->and($message->attempt_count)->toBe(0)
        ->and($message->published_at)->toBeNull()
        ->and($message->locked_until)->toBeNull()
        ->and($message->last_error)->toBe('RabbitMQ unavailable')
        ->and($message->available_at->isFuture())->toBeTrue();
});

it('stops the batch and releases the untried rows when the broker is unavailable', function (): void {
    $fake = fakeOutboxPublisher(handler: function (): void {
        throw new TransientPublishFailure('RabbitMQ unavailable');
    });
    $first = pendingOutboxMessage(['created_at' => now()->subMinutes(3)]);
    $second = pendingOutboxMessage(['created_at' => now()->subMinutes(2)]);
    $third = pendingOutboxMessage(['created_at' => now()->subMinute()]);

    $this->artisan('outbox:relay --once');

    expect($fake->attempted)->toBe([$first->getKey()])
        ->and($second->refresh()->attempt_count)->toBe(0)
        ->and($second->locked_until)->toBeNull()
        ->and($second->available_at->isFuture())->toBeTrue()
        ->and($second->last_error)->toBeNull()
        ->and($third->refresh()->attempt_count)->toBe(0);
});

it('keeps publishing the rest of the batch when one message is at fault', function (): void {
    $bad = null;
    $fake = fakeOutboxPublisher(handler: function (OutboxMessage $message) use (&$bad): void {
        if ($message->getKey() === $bad->getKey()) {
            throw new RuntimeException('payload is invalid');
        }
    });
    $bad = pendingOutboxMessage(['created_at' => now()->subMinutes(2)]);
    $good = pendingOutboxMessage(['created_at' => now()->subMinute()]);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([$good->getKey()])
        ->and($bad->refresh()->attempt_count)->toBe(1)
        ->and($bad->last_error)->toBe('payload is invalid');
});

it('claims no more than the configured batch size', function (): void {
    config(['bulk-imports.outbox.batch_size' => 2]);
    $fake = fakeOutboxPublisher();
    foreach (range(1, 5) as $i) {
        pendingOutboxMessage(['created_at' => now()->subMinutes(10 - $i)]);
    }

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toHaveCount(2);
});

it('retries parked messages with a fresh set of attempts', function (): void {
    $fake = fakeOutboxPublisher();
    $parked = pendingOutboxMessage([
        'attempt_count' => config('bulk-imports.outbox.max_attempts'),
        'last_error' => 'unroutable',
    ]);
    $healthy = pendingOutboxMessage(['attempt_count' => 2, 'available_at' => now()->addHour()]);

    $this->artisan('outbox:relay --once');
    expect($fake->published)->toBe([]);

    $this->artisan('outbox:retry-parked')
        ->expectsOutput('Released 1 parked message(s) for retry.')
        ->assertSuccessful();

    expect($parked->refresh()->attempt_count)->toBe(0)
        ->and($healthy->refresh()->attempt_count)->toBe(2);

    $this->artisan('outbox:relay --once');

    expect($fake->published)->toBe([$parked->getKey()]);
});

it('retries a single parked message by id', function (): void {
    $max = config('bulk-imports.outbox.max_attempts');
    $one = pendingOutboxMessage(['attempt_count' => $max]);
    $two = pendingOutboxMessage(['attempt_count' => $max]);

    $this->artisan('outbox:retry-parked', ['id' => $one->getKey()])
        ->expectsOutput('Released 1 parked message(s) for retry.');

    expect($one->refresh()->attempt_count)->toBe(0)
        ->and($two->refresh()->attempt_count)->toBe($max);
});
