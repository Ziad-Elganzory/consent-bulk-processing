<?php

// The one exchange every command message is published to. The outbox relay reads it
// from 'command_exchange', so the name only has to be defined here.
$commandExchange = env('RABBITMQ_COMMAND_EXCHANGE', 'consent.commands');

return [
    'command_exchange' => $commandExchange,

    'exchanges' => [
        $commandExchange => [
            'type' => 'direct',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    'queues' => [
        'consent.parse' => [
            'type' => 'quorum',
            'durable' => true,
            'auto_delete' => false,
        ],
        'consent.validate' => [
            'type' => 'quorum',
            'durable' => true,
            'auto_delete' => false,
        ],
        'consent.assemble' => [
            'type' => 'quorum',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    // Each routing key is the type of a message in app/Infrastructure/Messaging/Protocol/Messages.
    'bindings' => [
        [
            'exchange' => $commandExchange,
            'queue' => 'consent.parse',
            'routing_key' => 'consent.parse.requested',
        ],
        [
            'exchange' => $commandExchange,
            'queue' => 'consent.validate',
            'routing_key' => 'consent.chunk.validate',
        ],
        [
            'exchange' => $commandExchange,
            'queue' => 'consent.assemble',
            'routing_key' => 'consent.import.assemble',
        ],
    ],
];
