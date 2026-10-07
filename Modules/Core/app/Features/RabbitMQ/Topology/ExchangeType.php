<?php

namespace Modules\Core\Features\RabbitMQ\Topology;

enum ExchangeType: string
{
    case Topic = 'topic';     // Routing key patterns: "bulk.#", "bulk.*.ready".
    case Direct = 'direct';   // Exact routing key match.
    case Fanout = 'fanout';   // Every bound queue; the routing key is ignored.
    case Headers = 'headers'; // Matches on message headers.
}
