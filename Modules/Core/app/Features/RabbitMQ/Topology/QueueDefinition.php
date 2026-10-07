<?php

namespace Modules\Core\Features\RabbitMQ\Topology;

use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Exceptions\InvalidMessagingDefinition;
use Modules\Core\Features\RabbitMQ\Scaling\ConsumerScaling;
use Modules\Core\Features\RabbitMQ\Scaling\FixedConsumerScaling;

/**
 * A queue a module consumes, bound to an exchange under one or more routing keys.
 *
 * Declaring it creates three durable quorum queues:
 *
 *   {name}         the work queue; messages the consumer rejects dead-letter to {name}.dead
 *   {name}.retry   no consumers: a failed message waits retryDelaySeconds, then returns to {name}
 *   {name}.dead    messages that used up maxAttempts, kept for inspection
 *
 * Retries and dead letters go through the default exchange straight to these queues,
 * so other queues bound to the same routing key never see them twice.
 */
final readonly class QueueDefinition
{
    /**
     * @param  list<string>  $routingKeys  messages of $exchange this queue receives
     * @param  class-string<MessageHandler>  $handler
     * @param  int  $maxAttempts  deliveries before the message goes to the dead queue
     * @param  int  $prefetch  unacknowledged messages one consumer may hold
     * @param  ConsumerScaling  $scaling  how many consumers rabbitmq:work runs for it
     */
    public function __construct(
        public string $name,
        public string $exchange,
        public array $routingKeys,
        public string $handler,
        public int $maxAttempts = MessagingConstants::DEFAULT_MAX_ATTEMPTS,
        public int $retryDelaySeconds = MessagingConstants::DEFAULT_RETRY_DELAY_SECONDS,
        public int $prefetch = MessagingConstants::DEFAULT_PREFETCH,
        public ConsumerScaling $scaling = new FixedConsumerScaling,
    ) {
        if ($routingKeys === []) {
            throw InvalidMessagingDefinition::queueWithoutRoutingKeys($name);
        }

        if ($maxAttempts < 1 || $retryDelaySeconds < 1 || $prefetch < 1) {
            throw InvalidMessagingDefinition::queueLimitBelowOne($name);
        }
    }

    public function retryQueueName(): string
    {
        return $this->name.MessagingConstants::RETRY_QUEUE_SUFFIX;
    }

    public function deadQueueName(): string
    {
        return $this->name.MessagingConstants::DEAD_QUEUE_SUFFIX;
    }

    /**
     * @return array<string, mixed>
     */
    public function workQueueArguments(): array
    {
        return [
            'x-queue-type' => MessagingConstants::QUEUE_TYPE,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $this->deadQueueName(),
        ];
    }

    /**
     * When a message's TTL expires the broker dead-letters it back to the work queue.
     * At-least-once dead-lettering (which needs reject-publish overflow) keeps a waiting
     * retry from being lost if the broker restarts mid-move.
     *
     * @return array<string, mixed>
     */
    public function retryQueueArguments(): array
    {
        return [
            'x-queue-type' => MessagingConstants::QUEUE_TYPE,
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
        return [
            'x-queue-type' => MessagingConstants::QUEUE_TYPE,
        ];
    }
}
