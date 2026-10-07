<?php

namespace Modules\Core\Features\RabbitMQ\Contracts;

use Modules\Core\Features\RabbitMQ\Topology\ExchangeDefinition;
use Modules\Core\Features\RabbitMQ\Topology\QueueDefinition;

/**
 * The one place a module declares its messaging: the exchanges it publishes to and the
 * queues it consumes, with the names as constants on the implementing class.
 *
 * Registered through the module's service provider ($messaging) and collected by the
 * MessagingRegistry, which every command reads from.
 */
interface ModuleMessaging
{
    /**
     * @return list<ExchangeDefinition>
     */
    public function exchanges(): array;

    /**
     * Each queue is bound to an exchange: the module's own, or another module's.
     *
     * @return list<QueueDefinition>
     */
    public function queues(): array;
}
