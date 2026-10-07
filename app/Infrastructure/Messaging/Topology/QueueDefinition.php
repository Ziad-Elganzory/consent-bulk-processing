<?php

namespace App\Infrastructure\Messaging\Topology;

use App\Infrastructure\Messaging\Contracts\MessageHandler;

/**
 * One queue a domain consumes. Besides the queue itself, two companions are declared:
 *
 *  - "{name}.retry" holds a failed message for retryDelaySeconds, then hands it back;
 *  - "{name}.dead" keeps messages that failed every attempt or could not be read.
 *
 * Both are reached through the default exchange, so no other queue ever sees those copies.
 */
final readonly class QueueDefinition
{
    /**
     * @param  list<string>  $routingKeys  message types this queue receives
     * @param  int  $maxAttempts  deliveries before a failing message goes to the dead queue
     * @param  int  $prefetch  messages one consumer may hold unacknowledged
     * @param  class-string<MessageHandler>|null  $handler  null until the queue has a consumer
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

    public function retryQueue(): string
    {
        return $this->name.'.retry';
    }

    public function deadQueue(): string
    {
        return $this->name.'.dead';
    }

    /**
     * Every queue to declare for this definition, keyed by name, work queue first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function declarations(): array
    {
        return [
            // A message the consumer rejects is moved to the dead queue.
            $this->name => [
                'x-queue-type' => 'quorum',
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->deadQueue(),
            ],
            // Nothing consumes this queue: a message waits out its TTL, then RabbitMQ moves it
            // back to the work queue. At-least-once dead-lettering keeps that move from losing
            // a message if the broker restarts, and requires reject-publish overflow.
            $this->retryQueue() => [
                'x-queue-type' => 'quorum',
                'x-message-ttl' => $this->retryDelaySeconds * 1000,
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->name,
                'x-dead-letter-strategy' => 'at-least-once',
                'x-overflow' => 'reject-publish',
            ],
            $this->deadQueue() => [
                'x-queue-type' => 'quorum',
            ],
        ];
    }
}
