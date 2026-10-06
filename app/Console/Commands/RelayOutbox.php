<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Outbox\Services\OutboxRelay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outbox:relay {--once : Process one batch and exit} {--sleep=1 : Seconds to wait when idle}')]
#[Description('Publish pending outbox messages to RabbitMQ.')]
class RelayOutbox extends Command
{
    public function handle(OutboxRelay $relay): int
    {
        $running = true;

        $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
            $running = false;
        });

        do {
            $published = $relay->relayBatch();

            if ($published > 0) {
                $this->info("Published {$published} message(s).");
            }

            if ($this->option('once')) {
                break;
            }

            if ($published === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while ($running);

        return self::SUCCESS;
    }
}
