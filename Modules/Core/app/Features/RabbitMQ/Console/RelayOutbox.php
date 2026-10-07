<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Publishing\Outbox;

/**
 * Long-running: publishes outbox messages as they are recorded. Stops cleanly on SIGTERM
 * or SIGINT after the batch in progress.
 */
class RelayOutbox extends Command
{
    protected $signature = 'rabbitmq:relay-outbox
        {--batch='.MessagingConstants::OUTBOX_RELAY_BATCH_SIZE.' : Messages published per database transaction}
        {--once : Relay one batch and exit}';

    protected $description = 'Publish the messages recorded in the outbox to RabbitMQ';

    private bool $stopRequested = false;

    public function handle(Outbox $outbox): int
    {
        // The command object can be reused within one process (Artisan::call), so each run starts fresh.
        $this->stopRequested = false;

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopRequested = true;
        });

        $batchSize = (int) $this->option('batch');

        do {
            $published = $outbox->relayBatch($batchSize);

            if ($published > 0) {
                $this->components->info("Published {$published} message(s).");
            }

            if ($this->option('once')) {
                break;
            }

            // A full batch means more may be waiting; otherwise wait for new messages.
            if ($published < $batchSize && ! $this->stopRequested) {
                Sleep::for(MessagingConstants::OUTBOX_RELAY_IDLE_SECONDS)->seconds();
            }
        } while (! $this->stopRequested);

        return self::SUCCESS;
    }
}
