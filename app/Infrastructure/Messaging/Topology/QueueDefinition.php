<?php

namespace App\Infrastructure\Messaging\Topology;

use App\Infrastructure\Messaging\Contracts\MessageHandler;

/**
 * A queue a module consumes, bound to an exchange under one or more routing keys
 * (message types). Declaring it creates three durable quorum queues:
 *
 *   {name}         the work queue; rejected messages dead-letter to {name}.dead
 *   {name}.retry   no consumers; a failed message waits retryDelaySeconds, then returns to {name}
 *   {name}.dead    messages that used up maxAttempts, kept for inspection
 */
final readonly class QueueDefinition
{
    /**
     * @param  list<string>  $routingKeys
     * @param  int  $maxAttempts  deliveries before a message goes to the dead queue
     * @param  int  $prefetch  unacknowledged messages one consumer may hold
     * @param  class-string<MessageHandler>|null  $handler  null while the queue has no consumer yet
     */
    public function __construct(
        public string $name,
        public string $exchange,
        public array $routingKeys,
        public int $maxAttempts,
        public int $retryDelaySeconds,
        public int $prefetch,
        public ?string $handler = null,
    ) {}

    public function retryQueueName(): string
    {
        return "{$this->name}.retry";
    }

    public function deadQueueName(): string
    {
        return "{$this->name}.dead";
    }

    /**
     * @return array<string, mixed>
     */
    public function workQueueArguments(): array
    {
        return [
            'x-queue-type' => 'quorum',
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $this->deadQueueName(),
        ];
    }

    /**
     * When a message's TTL expires the broker moves it back to the work queue.
     * At-least-once dead-lettering keeps a waiting retry from being lost if the broker
     * restarts mid-move, and requires reject-publish overflow.
     *
     * @return array<string, mixed>
     */
    public function retryQueueArguments(): array
    {
        return [
            'x-queue-type' => 'quorum',
            'x-message-ttl' => $this->retryDelaySeconds * 1000,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $this->name,
            'x-dead-letter-strategy' => 'at-least-once',
            'x-overflow' => 'reject-publish',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deadQueueArguments(): array
    {
        return ['x-queue-type' => 'quorum'];
    }
}
