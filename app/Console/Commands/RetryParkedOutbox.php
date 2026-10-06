<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outbox:retry-parked {id? : Retry only the message with this id}')]
#[Description('Make parked outbox messages claimable again, with a fresh set of attempts.')]
class RetryParkedOutbox extends Command
{
    public function handle(): int
    {
        $query = OutboxMessage::query()->parked();

        if ($this->argument('id') !== null) {
            $query->whereKey($this->argument('id'));
        }

        $retried = $query->update([
            'attempt_count' => 0,
            'locked_until' => null,
            'available_at' => now(),
        ]);

        $this->info("Released {$retried} parked message(s) for retry.");

        return self::SUCCESS;
    }
}
