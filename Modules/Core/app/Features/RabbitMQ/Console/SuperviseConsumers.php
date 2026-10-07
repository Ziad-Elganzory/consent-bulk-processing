<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Scaling\ConsumerProcessPool;
use Modules\Core\Features\RabbitMQ\Scaling\ConsumerScaler;
use Modules\Core\Features\RabbitMQ\Scaling\QueueInspector;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;
use Modules\Core\Features\RabbitMQ\Topology\QueueDefinition;
use Throwable;

/**
 * Long-running: runs rabbitmq:consume processes for the selected queues and scales them
 * with the queues' backlog, within each queue's ConsumerScaling and this supervisor's own
 * process cap. Run one per worker container; several containers share each queue's limits.
 *
 * Stops on SIGTERM/SIGINT: every consumer finishes its message first.
 */
class SuperviseConsumers extends Command
{
    protected $signature = 'rabbitmq:work
        {--queue=* : Queues to run, by name or pattern (orders.*); all declared queues when omitted}
        {--max-processes='.MessagingConstants::SUPERVISOR_MAX_PROCESSES.' : Most consumer processes this supervisor runs at once}
        {--max-time=0 : Stop after this many seconds (0 = no limit)}';

    protected $description = 'Run and auto-scale consumers for the declared queues';

    private bool $stopRequested = false;

    /** @var array<string, int> queue => when it last had ready messages (unix time) */
    private array $lastBusyAt = [];

    public function handle(MessagingRegistry $registry, QueueInspector $inspector): int
    {
        $queues = $this->selectedQueues($registry);

        if ($queues === []) {
            $this->components->error('No declared queue matches '.implode(', ', $this->option('queue')).'; see rabbitmq:topology.');

            return self::FAILURE;
        }

        $this->stopRequested = false;
        $this->lastBusyAt = [];
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopRequested = true;
        });

        $pool = $this->laravel->make(ConsumerProcessPool::class, [
            'onOutput' => fn (string $queue, string $line) => $this->line("[{$queue}] {$line}"),
        ]);
        $maxProcesses = (int) $this->option('max-processes');
        $maxSeconds = (int) $this->option('max-time');
        $startedAt = now()->getTimestamp();

        $this->components->info('Supervising '.implode(', ', array_map(fn (QueueDefinition $queue): string => $queue->name, $queues)).'.');

        while (! $this->stopRequested && ($maxSeconds === 0 || now()->getTimestamp() - $startedAt < $maxSeconds)) {
            $pool->removeExited();

            foreach ($queues as $queue) {
                $this->scale($queue, $inspector, $pool, $maxProcesses);
            }

            Sleep::for(MessagingConstants::SUPERVISOR_TICK_SECONDS)->seconds();
        }

        $this->components->info('Stopping consumers…');
        $pool->stopAll();

        return self::SUCCESS;
    }

    /**
     * @return list<QueueDefinition>
     */
    private function selectedQueues(MessagingRegistry $registry): array
    {
        $patterns = $this->option('queue') ?: ['*'];

        return array_values(array_filter($registry->queues(), fn (QueueDefinition $queue): bool => Str::is($patterns, $queue->name)));
    }

    private function scale(QueueDefinition $queue, QueueInspector $inspector, ConsumerProcessPool $pool, int $maxProcesses): void
    {
        try {
            $load = $inspector->load($queue->name);
        } catch (Throwable $exception) {
            // Broker unreachable or queue not declared: keep the consumers as they are.
            $this->components->warn("{$queue->name}: {$exception->getMessage()}");

            return;
        }

        if ($load->readyMessages > 0 || ! isset($this->lastBusyAt[$queue->name])) {
            $this->lastBusyAt[$queue->name] = now()->getTimestamp();
        }

        $change = ConsumerScaler::change(
            $load,
            $queue->scaling,
            localConsumers: $pool->runningCount($queue->name),
            freeProcessSlots: $maxProcesses - $pool->totalRunning(),
            idleSeconds: now()->getTimestamp() - $this->lastBusyAt[$queue->name],
        );

        for ($started = 0; $started < $change; $started++) {
            $pool->start($queue->name);
        }

        for ($stopped = 0; $stopped < -$change; $stopped++) {
            $pool->stopOne($queue->name);
        }

        if ($change !== 0) {
            $this->line(sprintf('%s  %s  %+d consumer(s) → %d here, %d ready', now()->toDateTimeString(), $queue->name, $change, $pool->runningCount($queue->name), $load->readyMessages));
        }

        if ($change < 0) {
            // Each scale-down waits a full cooldown before the next one.
            $this->lastBusyAt[$queue->name] = now()->getTimestamp();
        }
    }
}
