<?php

it('refuses to consume a queue that is not declared', function (): void {
    $this->artisan('rabbitmq:consume', ['queue' => 'missing'])
        ->expectsOutputToContain('is not declared')
        ->assertFailed();
});

it('refuses to consume a queue that has no handler yet', function (): void {
    $this->artisan('rabbitmq:consume', ['queue' => config('bulk-imports.messaging.queues.assemble')])
        ->expectsOutputToContain('has no handler yet')
        ->assertFailed();
});
