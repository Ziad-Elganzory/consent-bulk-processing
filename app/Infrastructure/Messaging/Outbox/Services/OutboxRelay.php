<?php

namespace App\Infrastructure\Messaging\Outbox\Services;

use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxRelay
{
    public function __construct(private OutboxPublisher $publisher) {}

    /**
     * Claims one batch of due messages and publishes each of them.
     *
     * A failure caused by the message is recorded against that message and the
     * batch continues. A failure caused by the broker puts the rest of the batch
     * back untouched and ends the batch.
     *
     * @return int the number of messages the broker confirmed
     */
    public function relayBatch(): int
    {
        $published = 0;
        $messages = $this->claimBatch();

        foreach ($messages as $index => $message) {
            try {
                $this->publisher->publish($message);

                $message->forceFill([
                    'published_at' => now(),
                    'locked_until' => null,
                    'last_error' => null,
                ])->save();

                $published++;
            } catch (TransientPublishFailure $exception) {
                $this->releaseWithoutAttempt($messages->slice($index)->values(), $exception->getMessage());

                break;
            } catch (Throwable $exception) {
                $message->forceFill([
                    'locked_until' => null,
                    'last_error' => $exception->getMessage(),
                    'available_at' => now()->addSeconds(min(300, 2 ** $message->attempt_count)),
                ])->save();
            }
        }

        return $published;
    }

    /**
     * How long a claimed row stays leased, long enough to publish a whole batch.
     */
    public function leaseSeconds(): int
    {
        return config('bulk-imports.outbox.batch_size') * config('bulk-imports.outbox.publish_timeout_seconds')
            + config('bulk-imports.outbox.lease_buffer_seconds');
    }

    /**
     * Locks due rows and leases them, committing before any network call is made.
     *
     * @return Collection<int, OutboxMessage>
     */
    private function claimBatch(): Collection
    {
        return DB::transaction(function (): Collection {
            $messages = OutboxMessage::query()
                ->whereNull('published_at')
                ->where('available_at', '<=', now())
                ->where('attempt_count', '<', config('bulk-imports.outbox.max_attempts'))
                ->where(fn (Builder $query) => $query
                    ->whereNull('locked_until')
                    ->orWhere('locked_until', '<', now()))
                ->orderBy('created_at')
                ->limit(config('bulk-imports.outbox.batch_size'))
                ->lock('for update skip locked')
                ->get();

            foreach ($messages as $message) {
                $message->forceFill([
                    'locked_until' => now()->addSeconds($this->leaseSeconds()),
                    'attempt_count' => $message->attempt_count + 1,
                ])->save();
            }

            return $messages;
        });
    }

    /**
     * Returns claimed rows to the queue, undoing the attempt that claiming counted.
     *
     * @param  Collection<int, OutboxMessage>  $messages  the row that failed first, then the rows never tried
     */
    private function releaseWithoutAttempt(Collection $messages, string $error): void
    {
        OutboxMessage::query()
            ->whereKey($messages->modelKeys())
            ->update([
                'locked_until' => null,
                'attempt_count' => DB::raw('attempt_count - 1'),
                'available_at' => now()->addSeconds(config('bulk-imports.outbox.broker_retry_seconds')),
            ]);

        OutboxMessage::query()
            ->whereKey($messages->first()->getKey())
            ->update(['last_error' => $error]);
    }
}
