<?php

namespace App\Providers;

use App\Domains\BulkImport\Messaging\BulkImportMessaging;
use App\Infrastructure\Messaging\Outbox\Contracts\OutboxPublisher;
use App\Infrastructure\Messaging\Outbox\Publishers\AmqpOutboxPublisher;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OutboxPublisher::class, AmqpOutboxPublisher::class);

        $this->app->singleton(MessagingRegistry::class, fn (): MessagingRegistry => new MessagingRegistry([
            new BulkImportMessaging,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
