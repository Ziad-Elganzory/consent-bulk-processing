<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

use Closure;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Symfony\Component\Process\Process;

/**
 * The rabbitmq:consume child processes one supervisor runs, per queue. Stopping is always
 * graceful (SIGTERM): the consumer finishes its message first, and is counted as stopping
 * until it exits.
 */
class ConsumerProcessPool
{
    /** @var array<string, list<Process>> queue => running consumers */
    private array $running = [];

    /** @var list<Process> consumers asked to stop, not exited yet */
    private array $stopping = [];

    /**
     * @param  Closure(string, string): void|null  $onOutput  receives the queue and each output line
     */
    public function __construct(private readonly ?Closure $onOutput = null) {}

    public function start(string $queue): void
    {
        $process = new Process(
            [PHP_BINARY, base_path('artisan'), 'rabbitmq:consume', $queue, '--max-time='.MessagingConstants::CONSUMER_MAX_SECONDS],
            base_path(),
            timeout: null,
        );
        $process->start(fn (string $type, string $output) => $this->forwardOutput($queue, $output));

        $this->running[$queue][] = $process;
    }

    public function stopOne(string $queue): void
    {
        $process = array_pop($this->running[$queue]);

        if ($process !== null) {
            $process->signal(SIGTERM);
            $this->stopping[] = $process;
        }
    }

    public function runningCount(string $queue): int
    {
        return count($this->running[$queue] ?? []);
    }

    public function totalRunning(): int
    {
        return array_sum(array_map(count(...), $this->running));
    }

    /**
     * Forgets consumers that exited (their --max-time, a crash, a finished stop), so the
     * next tick starts replacements where the queue still needs them.
     */
    public function removeExited(): void
    {
        foreach ($this->running as $queue => $processes) {
            $this->running[$queue] = array_values(array_filter($processes, fn (Process $process): bool => $process->isRunning()));
        }

        $this->stopping = array_values(array_filter($this->stopping, fn (Process $process): bool => $process->isRunning()));
    }

    /**
     * Stops every consumer and waits for them, killing those still busy after the timeout.
     */
    public function stopAll(): void
    {
        foreach (array_keys($this->running) as $queue) {
            while ($this->runningCount($queue) > 0) {
                $this->stopOne($queue);
            }
        }

        foreach ($this->stopping as $process) {
            $process->stop(MessagingConstants::CONSUMER_STOP_TIMEOUT_SECONDS, SIGTERM);
        }

        $this->stopping = [];
    }

    private function forwardOutput(string $queue, string $output): void
    {
        if ($this->onOutput === null) {
            return;
        }

        foreach (preg_split('/\R/', rtrim($output)) as $line) {
            ($this->onOutput)($queue, $line);
        }
    }
}
