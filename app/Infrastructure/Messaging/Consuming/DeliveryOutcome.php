<?php

namespace App\Infrastructure\Messaging\Consuming;

/**
 * What the consumer did with one delivery.
 */
enum DeliveryOutcome: string
{
    case Handled = 'handled';
    case Retried = 'retried';
    case DeadLettered = 'dead-lettered';
    case Rejected = 'rejected';
}
