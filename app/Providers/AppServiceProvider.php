<?php

namespace App\Providers;

use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Publishers\AmqpOutboxPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OutboxPublisher::class, AmqpOutboxPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
