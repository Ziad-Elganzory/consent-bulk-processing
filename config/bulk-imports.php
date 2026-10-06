<?php

return [
    // The filesystem disk holding source files, chunks and results. Must point at the private MinIO bucket.
    'disk' => env('BULK_IMPORT_DISK', 's3'),
    'max_file_size_kb' => (int) env('BULK_IMPORT_MAX_FILE_SIZE_KB', 512 * 1024),
    'chunk_max_rows' => (int) env('BULK_IMPORT_CHUNK_MAX_ROWS', 10_000),
    'chunk_max_bytes' => (int) env('BULK_IMPORT_CHUNK_MAX_BYTES', 5 * 1024 * 1024),
    'max_row_bytes' => (int) env('BULK_IMPORT_MAX_ROW_BYTES', 16 * 1024),
    'artifact_retention_days' => (int) env('BULK_IMPORT_RETENTION_DAYS', 7),

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
