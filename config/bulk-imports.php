<?php

return [
    // The filesystem disk holding source files, chunks and results. Must point at the private MinIO bucket.
    'disk' => env('BULK_IMPORT_DISK', 's3'),
    'max_file_size_kb' => (int) env('BULK_IMPORT_MAX_FILE_SIZE_KB', 512 * 1024),
    'chunk_max_rows' => (int) env('BULK_IMPORT_CHUNK_MAX_ROWS', 10_000),
    'chunk_max_bytes' => (int) env('BULK_IMPORT_CHUNK_MAX_BYTES', 5 * 1024 * 1024),
    'max_row_bytes' => (int) env('BULK_IMPORT_MAX_ROW_BYTES', 16 * 1024),
    'artifact_retention_days' => (int) env('BULK_IMPORT_RETENTION_DAYS', 7),

    // Row rules for the consent schema. Limits are per cell, in characters.
    'validation' => [
        'max_lengths' => [
            'code' => (int) env('BULK_IMPORT_CODE_MAX_LENGTH', 64),
            'name' => (int) env('BULK_IMPORT_NAME_MAX_LENGTH', 255),
            'description' => (int) env('BULK_IMPORT_DESCRIPTION_MAX_LENGTH', 2000),
            'purpose' => (int) env('BULK_IMPORT_PURPOSE_MAX_LENGTH', 1000),
        ],
    ],

    // RabbitMQ names. Each routing key is also the type of its message, so change a
    // routing key only while no outbox rows of that type are waiting.
    'messaging' => [
        'exchange' => env('BULK_IMPORT_EXCHANGE', 'consent.commands'),
        'queues' => [
            'parse' => env('BULK_IMPORT_PARSE_QUEUE', 'consent.parse'),
            'validate' => env('BULK_IMPORT_VALIDATE_QUEUE', 'consent.validate'),
            'assemble' => env('BULK_IMPORT_ASSEMBLE_QUEUE', 'consent.assemble'),
        ],
        'routing_keys' => [
            'parse_requested' => env('BULK_IMPORT_PARSE_ROUTING_KEY', 'consent.parse.requested'),
            'validate_chunk' => env('BULK_IMPORT_VALIDATE_ROUTING_KEY', 'consent.chunk.validate'),
            'assemble_import' => env('BULK_IMPORT_ASSEMBLE_ROUTING_KEY', 'consent.import.assemble'),
        ],
        // Applied to every bulk import queue.
        'consumers' => [
            // Deliveries before a failing message moves to the dead queue.
            'max_attempts' => (int) env('BULK_IMPORT_CONSUMER_MAX_ATTEMPTS', 3),
            // How long a failed message waits in the retry queue before the next attempt.
            'retry_delay_seconds' => (int) env('BULK_IMPORT_CONSUMER_RETRY_DELAY_SECONDS', 30),
            // Unacknowledged messages one consumer may hold.
            'prefetch' => (int) env('BULK_IMPORT_CONSUMER_PREFETCH', 1),
        ],
        // How many consumers rabbitmq:work runs for the validate queue, by backlog.
        'scaling' => [
            'validate' => [
                'min_consumers' => (int) env('BULK_IMPORT_VALIDATE_MIN_CONSUMERS', 1),
                'max_consumers' => (int) env('BULK_IMPORT_VALIDATE_MAX_CONSUMERS', 5),
                // Seconds the queue must stay empty before an extra consumer stops.
                'scale_down_cooldown_seconds' => (int) env('BULK_IMPORT_VALIDATE_SCALE_DOWN_COOLDOWN_SECONDS', 60),
            ],
        ],
    ],
];
