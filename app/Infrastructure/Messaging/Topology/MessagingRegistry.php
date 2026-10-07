<?php

namespace App\Infrastructure\Messaging\Topology;

use App\Infrastructure\Messaging\Contracts\DeclaresMessaging;
use App\Infrastructure\Messaging\Contracts\MessageHandler;
use App\Infrastructure\Messaging\Protocol\MessageContract;
use InvalidArgumentException;
use LogicException;

/**
 * Every module's declarations in one place. Validates them when built, so a
 * mistake fails at boot instead of silently dropping messages.
 */
final class MessagingRegistry
{
    /** @var array<string, ExchangeDefinition> */
    private array $exchanges = [];

    /** @var array<string, QueueDefinition> */
    private array $queues = [];

    /** @var array<string, string> routing key => exchange name */
    private array $routes = [];

    /** @var array<string, class-string<MessageContract>> type => message class */
    private array $messages = [];

    /**
     * @param  list<DeclaresMessaging>  $modules
     *
     * @throws LogicException when the declarations contradict each other
     */
    public function __construct(array $modules)
    {
        foreach ($modules as $module) {
            foreach ($module->exchanges() as $exchange) {
                $this->exchanges[$exchange->name] = $exchange;
            }

            foreach ($module->queues() as $queue) {
                if (isset($this->queues[$queue->name])) {
                    throw new LogicException("Queue [{$queue->name}] is declared twice.");
                }

                $this->queues[$queue->name] = $queue;

                foreach ($queue->routingKeys as $routingKey) {
                    if (isset($this->routes[$routingKey])) {
                        throw new LogicException("Routing key [{$routingKey}] is bound to more than one queue.");
                    }

                    $this->routes[$routingKey] = $queue->exchange;
                }
            }

            foreach ($module->messages() as $messageClass) {
                $this->messages[$messageClass::type()] = $messageClass;
            }
        }

        foreach ($this->queues as $queue) {
            if (! isset($this->exchanges[$queue->exchange])) {
                throw new LogicException("Queue [{$queue->name}] uses undeclared exchange [{$queue->exchange}].");
            }

            if ($queue->handler !== null && ! is_subclass_of($queue->handler, MessageHandler::class)) {
                throw new LogicException("Handler [{$queue->handler}] of queue [{$queue->name}] does not implement MessageHandler.");
            }

            if (min($queue->maxAttempts, $queue->retryDelaySeconds, $queue->prefetch) < 1) {
                throw new LogicException("Queue [{$queue->name}] needs max attempts, retry delay and prefetch of at least 1.");
            }
        }

        foreach (array_keys($this->messages) as $type) {
            if (! isset($this->routes[$type])) {
                throw new LogicException("Message type [{$type}] is not bound to any queue.");
            }
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
     * @throws InvalidArgumentException when no module declares the queue
     */
    public function queue(string $name): QueueDefinition
    {
        return $this->queues[$name] ?? throw new InvalidArgumentException("Queue [{$name}] is not declared.");
    }

    /**
     * The exchange a message of this type is published to.
     */
    public function exchangeFor(string $type): string
    {
        return $this->routes[$type]
            ?? throw new InvalidArgumentException("Message type [{$type}] is not bound to any queue.");
    }

    /**
     * Rebuilds a typed message from its type and data.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when the type is unknown or the data is invalid
     */
    public function message(string $type, array $data): MessageContract
    {
        $messageClass = $this->messages[$type]
            ?? throw new InvalidArgumentException("Unknown message type [{$type}].");

        return $messageClass::fromData($data);
    }
}
