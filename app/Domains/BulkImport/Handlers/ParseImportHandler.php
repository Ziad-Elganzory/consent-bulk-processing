<?php

namespace App\Domains\BulkImport\Handlers;

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Exceptions\UnprocessableFile;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use App\Domains\BulkImport\Services\Csv\CsvChunker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Publishing\Outbox;
use Throwable;

/**
 * Splits an uploaded CSV into chunk files and queues one validation message per chunk.
 *
 * Safe to run more than once for the same message:
 *  - an import that is already past parsing is skipped;
 *  - chunk files have fixed names, so a retry overwrites them;
 *  - the move to validating, the chunk rows and the outbox messages commit in one
 *    transaction, and only the run that wins the move to validating creates them.
 */
final class ParseImportHandler implements MessageHandler
{
    private const int FAILURE_MESSAGE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly CsvChunker $chunker,
        private readonly Outbox $outbox,
    ) {}

    public function handle(Envelope $envelope): void
    {
        $message = ParseRequested::fromPayload($envelope->payload);
        $import = BulkImport::query()->find($message->bulkImportId);

        // Queued: start it. Parsing: an earlier attempt stopped part way, so redo it.
        // Anything else: this message was already handled.
        if ($import === null || ! in_array($import->status, [BulkImportStatus::Queued, BulkImportStatus::Parsing], true)) {
            return;
        }

        BulkImport::query()
            ->whereKey($import->getKey())
            ->where('status', BulkImportStatus::Queued->value)
            ->update(['status' => BulkImportStatus::Parsing->value]);

        try {
            $chunks = $this->chunker->split($message->sourceObjectKey, $import->original_filename, $this->chunkPrefix($message));
        } catch (UnprocessableFile $exception) {
            $this->fail($message, $exception->getMessage());

            return;
        }

        DB::transaction(function () use ($message, $chunks): void {
            // Exactly one run can change parsing to validating. A duplicate gets 0 rows and stops.
            $finished = BulkImport::query()
                ->whereKey($message->bulkImportId)
                ->where('status', BulkImportStatus::Parsing->value)
                ->update(['status' => BulkImportStatus::Validating->value]);

            if ($finished === 0) {
                return;
            }

            foreach ($chunks as $chunk) {
                $chunkRow = BulkImportChunk::query()->create([
                    'bulk_import_id' => $message->bulkImportId,
                    'sequence' => $chunk['sequence'],
                    'source_object_key' => $chunk['object_key'],
                ]);

                $this->outbox->record(new ValidateChunk($message->bulkImportId, $chunkRow->getKey(), $chunk['object_key']));
            }
        });
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        $this->fail(ParseRequested::fromPayload($envelope->payload), "Parsing failed: {$exception->getMessage()}");
    }

    /**
     * Marks the import failed and removes its chunks. Only an import still being parsed
     * is changed, so a late duplicate cannot undo a finished one.
     */
    private function fail(ParseRequested $message, string $reason): void
    {
        $this->chunker->discard($this->chunkPrefix($message));

        BulkImport::query()
            ->whereKey($message->bulkImportId)
            ->whereIn('status', [BulkImportStatus::Queued->value, BulkImportStatus::Parsing->value])
            ->update([
                'status' => BulkImportStatus::Failed->value,
                'failure_message' => Str::limit($reason, self::FAILURE_MESSAGE_MAX_LENGTH),
            ]);
    }

    /**
     * Chunks live next to the source: consent/import-{id}/source/source.csv
     * becomes consent/import-{id}/chunks.
     */
    private function chunkPrefix(ParseRequested $message): string
    {
        return dirname($message->sourceObjectKey, 2).'/chunks';
    }
}
