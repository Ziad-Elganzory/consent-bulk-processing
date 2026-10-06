# Consent Bulk Import

A Laravel application for authenticated bulk imports of consent data from large CSV files. The intended design stores the original upload, intermediate chunks, and generated artifacts in private MinIO storage, while RabbitMQ coordinates asynchronous processing.

> This README is the design reference. Built so far: the upload step, the outbox and its relay, the message envelope and typed messages, and the RabbitMQ topology. Not built yet: inbox deduplication, the parse, validate and assemble workers, and results and downloads on the dashboard.

## Project shape

Keep this as one Laravel application and one business domain: BulkImport. Filament provides the dashboard interface. Parsing, chunk validation, and assembly are separate jobs and services within that domain, not separate Laravel modules or deployable services.

A possible code layout as the feature is implemented:

- app/Domains/BulkImport/Actions
- app/Domains/BulkImport/Enums
- app/Domains/BulkImport/Jobs
- app/Domains/BulkImport/Models
- app/Domains/BulkImport/Services/Csv
- app/Filament/Resources/BulkImports
- app/Infrastructure/Messaging/Outbox
- app/Infrastructure/Messaging/Inbox

RabbitMQ messages should carry import IDs, chunk IDs, and object-storage references, never the CSV contents.

## Intended import flow

1. A user starts an import in the dashboard. The application creates an import record and uploads the source CSV to a private MinIO object key. The dashboard checks upload-level constraints such as required file, extension, and maximum size. It does not parse or validate every row.
2. After the upload completes, the application confirms the object exists and records its metadata. In one database transaction it moves the import to queued and writes an ImportRequested message to the outbox.
3. An outbox relay publishes the message to RabbitMQ and marks it published only after broker confirmation. There is one parse request per import.
4. The parse-and-chunk worker streams the source object from MinIO. It checks the CSV structure and expected headers, then writes ordered chunk objects to MinIO. It records each chunk's sequence, object key, and status. CSV records must be read with a CSV-aware parser so quoted fields containing newlines are not split incorrectly.
5. Once parsing and chunk creation have finished successfully, the application queues validation for each chunk. Waiting until parsing finishes keeps the demo flow simple and avoids validating chunks from a file whose later structure may prove malformed.
6. Validation workers consume one message per chunk. Each worker streams its chunk, applies the row rules using bounded memory, and writes processed output and rejected-row details to MinIO. It updates chunk status and aggregate counts in the database. CSV rows are not stored in database tables.
7. When parsing is complete and all expected chunks are in a terminal state, the application queues one assembly request for the import.
8. The assembler reads successful chunk artifacts in sequence order and streams the final CSV and error report to MinIO. It marks the import complete or complete with errors. The dashboard displays status and authorized download links.

Dashboard
  -> upload source -> MinIO
       -> ImportRequested -> RabbitMQ parse queue
            -> parse and chunk -> MinIO chunk objects
                 -> ValidateChunk messages (one per chunk)
                      -> validation workers -> MinIO result/error objects
                           -> AssembleImport -> RabbitMQ assembly queue
                                -> assembler -> MinIO final artifacts
                                     -> dashboard status and downloads

## State and persistence

Suggested import states:

awaiting_upload -> queued -> parsing -> validating -> assembling
                                                     -> completed
                                                     -> completed_with_errors
                                                     -> failed

Suggested chunk states:

pending -> processing -> completed
                     -> failed

Worker leases or processing timestamps are needed so work can be recovered after a worker crashes.

Persist import-level metadata, not each CSV row. A BulkImport record can hold its owner, status, source object key and metadata, schema/rules version, expected chunk count, aggregate counts, output and error object keys, failure details, and lifecycle timestamps.

A BulkImportChunk record can hold the import ID, sequence number, source row range, chunk object key, result/error object keys, status, attempt or lease data, and row counts.

The outbox stores a stable message ID, type/version, routing key, payload, and publish/retry metadata. The inbox uses a unique consumer-name/message-ID pair to deduplicate redelivered messages and track handling. Neither table provides exactly-once processing by itself: the design is at-least-once delivery with idempotent handlers.

## RabbitMQ topology

For the demo, use durable queues with one message per unit of work:

| Queue | Message | Work unit |
| --- | --- | --- |
| bulk.parse | ImportRequested | One import |
| bulk.validate | ValidateChunk | One chunk |
| bulk.assemble | AssembleImport | One import |

A direct command exchange such as bulk.commands can route messages with keys such as import.parse, chunk.validate, and import.assemble. Add durable retry/dead-letter handling, and scale validation consumers independently when useful.

The outbox makes database state changes and message intent atomic. The relay publishes and waits for publisher confirms. Consumers claim an inbox entry and make their database updates idempotently. Use deterministic MinIO keys and verify checksums so a retry can safely find or replace the intended artifact. Use conditional state transitions and recover expired worker leases.

If processing later calls an external consent API, pass a stable idempotency key such as import ID plus source-row number when that API supports it. Whether the demo calls that service is still an open product decision.

## CSV and result behavior

- Stream the source, chunks, and outputs; do not load the full file or a full chunk into application memory.
- Choose chunking limits by both row count and bytes, and cap record and field sizes.
- Validate headers and file structure before scheduling row validation.
- Define explicit behavior for malformed CSV, missing or unexpected columns, and rows with the wrong number of fields.
- A simple initial policy is to keep valid rows for the final output and put rejected rows with reasons into a separate error report. The final output format is still to be decided.
- Record which schema and validation-rules version was used for each import.

## Storage and access

Keep source files, chunks, results, and error reports private in MinIO. Use streaming reads and writes, or multipart uploads where appropriate. Avoid building complete file contents as strings. Authorize every download through the dashboard and define a retention and cleanup policy for temporary artifacts.

## Current project setup and follow-up configuration

The repository is the fresh Laravel application discussed for this project. At the time of the project review, the installed direct dependencies included Laravel Framework 13.34, Filament 5.9, the RabbitMQ queue driver 15.0.2, and AWS Flysystem 3.35.3. Confirm installed package versions before relying on package-specific APIs.

Local setup status:

- Source files are stored through the S3 driver in the private MinIO bucket. The disk is set with BULK_IMPORT_DISK, and Compose creates the bucket with anonymous access disabled.
- The example environment matches the Compose services: MySQL, Redis, MinIO, and RabbitMQ. MySQL is required, because the outbox relay claims rows with SKIP LOCKED and concurrent workers share the database.
- Limits for file size, chunking, retention, and the outbox relay are environment driven. See config/bulk-imports.php and the matching entries in .env.example.
- The RabbitMQ topology is declared in code (app/Domains/BulkImport/Messaging/BulkImportMessaging.php) and applied with the artisan command rabbitmq:topology:declare. Retry and dead-letter queues are still to add. See app/Infrastructure/Messaging/README.md for the messaging SDK.
- The outbox relay runs as its own Compose service, named relay. Worker processes will be added the same way as they are built.
- The nwidart modules package is installed but unused. The agreed design is one BulkImport domain in the Laravel application, so module scaffolding is not required for the demo.

To run it locally: start the services with vendor/bin/sail up -d, run vendor/bin/sail artisan migrate, then declare the topology. Parked outbox messages can be retried with vendor/bin/sail artisan outbox:retry-parked.

## Decisions to settle before implementing the pipeline

1. Does a valid row call the consent service, or does this demo only validate and produce files? 
- this demo is only for reciving the huge file and chunk it and validate it and then assemble it , we will have 3 workers , 1 for chunking , 1 for validation , and 1 for assembly. 
2. Does the final CSV contain valid rows only, or all rows with a processing status? Should rejected rows also be written to a separate report?

rows that fails the validation shouldn't be uploaded to the minio it should have a file with the rejected rows in minio

3. What are the required headers, row-level validation rules, and schema version?

i want to make the validation step dynaminc and extendable , where i can sepecify the validation rules myself and change it any time i want.

4. What file-size, row-size, chunk-size, retry, and artifact-retention limits should apply?

we should sepecify a config file with these data so we can decide the amount later in the env
