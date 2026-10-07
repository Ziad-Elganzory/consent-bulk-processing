<?php

namespace Modules\Core\Features\RabbitMQ\Topology;

use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Contracts\ModuleMessaging;
use Modules\Core\Features\RabbitMQ\Exceptions\InvalidMessagingDefinition;

/**
 * Every exchange and queue declared by the registered ModuleMessaging classes, checked
 * for consistency as it loads. The single source the topology, consume and listing
 * commands read from.
 */
final class MessagingRegistry
{
    /** @var array<string, ExchangeDefinition> keyed by name */
    private array $exchanges = [];

    /** @var array<string, QueueDefinition> keyed by name */
    private array $queues = [];

    /** @var array<string, class-string<ModuleMessaging>> exchange name => the class declaring it */
    private array $exchangeOwners = [];

    /** @var array<string, class-string<ModuleMessaging>> queue name => the class declaring it */
    private array $queueOwners = [];

    /**
     * @param  iterable<ModuleMessaging>  $modules
     *
     * @throws InvalidMessagingDefinition
     */
    public function __construct(iterable $modules)
    {
        foreach ($modules as $module) {
            $this->registerModule($module);
        }

        foreach ($this->queues as $queue) {
            $this->assertQueueIsConsistent($queue);
        }
    }

    /**
     * @return list<ExchangeDefinition>
     */
    public function exchanges(): array
    {
        return array_values($this->exchanges);
    }

    /**
     * @return list<QueueDefinition>
     */
    public function queues(): array
    {
        return array_values($this->queues);
    }

    /**
     * @throws InvalidMessagingDefinition when no module declares $name
     */
    public function queue(string $name): QueueDefinition
    {
        return $this->queues[$name] ?? throw InvalidMessagingDefinition::unknownQueue($name);
    }

    /**
     * @return class-string<ModuleMessaging>
     */
    public function ownerOf(ExchangeDefinition|QueueDefinition $definition): string
    {
        return $definition instanceof ExchangeDefinition
            ? $this->exchangeOwners[$definition->name]
            : $this->queueOwners[$definition->name];
    }

    private function registerModule(ModuleMessaging $module): void
    {
        foreach ($module->exchanges() as $exchange) {
            if (isset($this->exchanges[$exchange->name])) {
                throw InvalidMessagingDefinition::duplicateExchange($exchange->name, $this->exchangeOwners[$exchange->name], $module::class);
            }

            $this->exchanges[$exchange->name] = $exchange;
            $this->exchangeOwners[$exchange->name] = $module::class;
        }

        foreach ($module->queues() as $queue) {
            if (isset($this->queues[$queue->name])) {
                throw InvalidMessagingDefinition::duplicateQueue($queue->name, $this->queueOwners[$queue->name], $module::class);
            }

            $this->queues[$queue->name] = $queue;
            $this->queueOwners[$queue->name] = $module::class;
        }
    }

    private function assertQueueIsConsistent(QueueDefinition $queue): void
    {
        if (! isset($this->exchanges[$queue->exchange])) {
            throw InvalidMessagingDefinition::undeclaredExchange($queue->name, $queue->exchange);
        }

        if (! is_subclass_of($queue->handler, MessageHandler::class)) {
            throw InvalidMessagingDefinition::invalidHandler($queue->name, $queue->handler);
        }
    }
}
