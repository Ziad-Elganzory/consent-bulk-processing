<?php

use App\Infrastructure\Messaging\Consuming\ConsumerLimits;

it('never stops on count or time when those limits are zero', function (): void {
    expect((new ConsumerLimits(maxMemoryMegabytes: 4096))->reached(settledMessages: 1_000_000, runningSeconds: 1_000_000))->toBeFalse();
});

it('stops after the message limit', function (): void {
    $limits = new ConsumerLimits(maxMessages: 10, maxMemoryMegabytes: 4096);

    expect($limits->reached(9, 0))->toBeFalse()
        ->and($limits->reached(10, 0))->toBeTrue();
});

it('stops after the time limit', function (): void {
    $limits = new ConsumerLimits(maxSeconds: 60, maxMemoryMegabytes: 4096);

    expect($limits->reached(0, 59))->toBeFalse()
        ->and($limits->reached(0, 60))->toBeTrue();
});

it('stops when memory use passes the limit', function (): void {
    expect((new ConsumerLimits(maxMemoryMegabytes: 1))->reached(0, 0))->toBeTrue();
});
