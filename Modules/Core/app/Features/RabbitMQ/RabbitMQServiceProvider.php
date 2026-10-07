<?php

namespace Modules\Core\Features\RabbitMQ;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use Modules\Core\Features\RabbitMQ\Console\CheckRabbitMQConnection;
use Modules\Core\Features\RabbitMQ\Console\ConsumeQueue;
use Modules\Core\Features\RabbitMQ\Console\DeclareTopology;
use Modules\Core\Features\RabbitMQ\Console\ListTopology;
use Modules\Core\Features\RabbitMQ\Console\RelayOutbox;
use Modules\Core\Features\RabbitMQ\Console\SuperviseConsumers;
use Modules\Core\Features\RabbitMQ\Contracts\MessagePublisher;
use Modules\Core\Features\RabbitMQ\Models\OutboxMessage;
use Modules\Core\Features\RabbitMQ\Publishing\AmqpMessagePublisher;
use Modules\Core\Features\RabbitMQ\Topology\MessagingRegistry;
use Modules\Core\Kernel\Support\ContainerTags;
use Modules\Core\Kernel\Support\FeatureServiceProvider;

/**
 * RabbitMQ: the broker connection the modules use to message each other (php-amqplib),
 * configured from .env (RABBITMQ_*) through config/rabbitmq.php.
 *
 * Modules declare their exchanges and queues in one ModuleMessaging class (registered
 * through $messaging on their provider), publish through the Outbox, and handle
 * messages with a MessageHandler run by rabbitmq:consume — itself run and auto-scaled by
 * rabbitmq:work.
 */
class RabbitMQServiceProvider extends FeatureServiceProvider
{
    protected function key(): string
    {
        return 'rabbitmq';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(RabbitMQConnection::class);
        $this->app->singleton(MessagePublisher::class, AmqpMessagePublisher::class);
        $this->app->singleton(MessagingRegistry::class, fn ($app) => new MessagingRegistry($app->tagged(ContainerTags::MESSAGING)));
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckRabbitMQConnection::class,
                DeclareTopology::class,
                ListTopology::class,
                ConsumeQueue::class,
                RelayOutbox::class,
                SuperviseConsumers::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [OutboxMessage::class]])->daily();
        });
    }
}
