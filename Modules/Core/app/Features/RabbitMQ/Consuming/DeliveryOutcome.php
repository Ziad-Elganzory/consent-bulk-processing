<?php

namespace Modules\Core\Features\RabbitMQ\Consuming;

/**
 * What the consumer did with one delivery.
 */
enum DeliveryOutcome: string
{
    case Handled = 'handled';             // The handler succeeded; acknowledged.
    case Retried = 'retried';             // The handler failed with attempts left; moved to the retry queue.
    case DeadLettered = 'dead-lettered';  // The handler failed on its last attempt; moved to the dead queue.
    case Rejected = 'rejected';           // Not a valid envelope; rejected to the dead queue without handling.
}
