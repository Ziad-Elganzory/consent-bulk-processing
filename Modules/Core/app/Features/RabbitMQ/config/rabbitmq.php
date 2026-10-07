<?php

return [

    // The broker connection every RabbitMQ command and publisher uses.
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),

    'port' => (int) env('RABBITMQ_PORT', 5672),

    'user' => env('RABBITMQ_USER', 'guest'),

    'password' => env('RABBITMQ_PASSWORD', 'guest'),

    'vhost' => env('RABBITMQ_VHOST', '/'),

    // TLS (amqps)
    'secure' => (bool) env('RABBITMQ_SECURE', false),

    // seconds
    'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3.0),

    // seconds; must be at least twice the heartbeat, or php-amqplib refuses to connect
    'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 130.0),

    // seconds; 0 disables it
    'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 60),

    // TCP keepalive
    'keepalive' => (bool) env('RABBITMQ_KEEPALIVE', false),

];
