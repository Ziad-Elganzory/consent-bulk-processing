<?php

namespace App\Infrastructure\Messaging\Contracts;

use App\Infrastructure\Messaging\Protocol\MessageContract;
use App\Infrastructure\Messaging\Topology\ExchangeDefinition;
use App\Infrastructure\Messaging\Topology\QueueDefinition;

/**
 * A domain's declaration of what it publishes and consumes.
 */
interface ModuleMessaging
{
    /**
     * @return list<ExchangeDefinition>
     */
    public function exchanges(): array;

    /**
     * @return list<QueueDefinition>
     */
    public function queues(): array;

    /**
     * @return list<class-string<MessageContract>>
     */
    public function messages(): array;
}
