<?php

namespace App\Infrastructure\Messaging\Consuming;

/**
 * When a long-running consumer should stop, so its process manager starts it again with
 * fresh code and memory. Zero means no limit.
 */
final readonly class ConsumerLimits
{
    public function __construct(
        public int $maxMessages = 0,
        public int $maxSeconds = 0,
        public int $maxMemoryMegabytes = 128,
    ) {}

    public function reached(int $settledMessages, int $runningSeconds): bool
    {
        return ($this->maxMessages > 0 && $settledMessages >= $this->maxMessages)
            || ($this->maxSeconds > 0 && $runningSeconds >= $this->maxSeconds)
            || memory_get_usage(true) >= $this->maxMemoryMegabytes * 1024 * 1024;
    }
}
