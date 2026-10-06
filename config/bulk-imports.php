<?php

return [
    // The filesystem disk holding source files, chunks and results. Must point at the private MinIO bucket.
    'disk' => env('BULK_IMPORT_DISK', 's3'),
    'max_file_size_kb' => (int) env('BULK_IMPORT_MAX_FILE_SIZE_KB', 512 * 1024),
    'chunk_max_rows' => (int) env('BULK_IMPORT_CHUNK_MAX_ROWS', 10_000),
    'chunk_max_bytes' => (int) env('BULK_IMPORT_CHUNK_MAX_BYTES', 5 * 1024 * 1024),
    'max_row_bytes' => (int) env('BULK_IMPORT_MAX_ROW_BYTES', 16 * 1024),
    'artifact_retention_days' => (int) env('BULK_IMPORT_RETENTION_DAYS', 7),

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
    ],

    'outbox' => [
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 10),
        // The longest the relay waits for the broker to confirm one message.
        'publish_timeout_seconds' => (int) env('OUTBOX_PUBLISH_TIMEOUT_SECONDS', 5),
        // Added to batch_size * publish_timeout_seconds to get the lease on a claimed row.
        'lease_buffer_seconds' => (int) env('OUTBOX_LEASE_BUFFER_SECONDS', 10),
        // How long rows wait after the broker was unreachable. These retries do not count as attempts.
        'broker_retry_seconds' => (int) env('OUTBOX_BROKER_RETRY_SECONDS', 5),
        // Failures caused by the message itself. Rows that reach this are parked.
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),
    ],
];
