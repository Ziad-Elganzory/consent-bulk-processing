<?php

namespace App\Domains\BulkImport\Services;

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Exceptions\UnprocessableFile;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use App\Domains\BulkImport\Services\Csv\CsvChunker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Features\RabbitMQ\Publishing\Outbox;

/**
 * Splits an uploaded CSV into chunk files and queues one validation message per chunk.
 *
 * Safe to run more than once for the same request: chunk files have fixed names, and
 * only the run that moves the import from parsing to validating creates the chunk rows
 * and outbox messages. Every other run finds the import already moved on and stops.
 */
final class ImportParser
{
    private const int FAILURE_MESSAGE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly CsvChunker $chunker,
        private readonly Outbox $outbox,
    ) {}

    public function parse(ParseRequested $request): void
    {
        $import = BulkImport::query()->find($request->bulkImportId);

        // Queued: first attempt. Parsing: an earlier attempt stopped part way, so redo it.
        // Anything else: this request was already handled.
        if ($import === null || ! in_array($import->status, [BulkImportStatus::Queued, BulkImportStatus::Parsing], true)) {
            return;
        }

        $this->move($request, [BulkImportStatus::Queued], BulkImportStatus::Parsing);

        try {
            $chunks = $this->chunker->split($request->sourceObjectKey, $import->original_filename, $this->chunkPrefix($request));
        } catch (UnprocessableFile $exception) {
            $this->fail($request, $exception->getMessage());

            return;
        }

        $this->queueChunks($request, $chunks);
    }

    /**
     * Marks the import failed and removes its chunk files. Only an import still being
     * parsed is changed, so a late duplicate cannot undo a finished one.
     */
    public function fail(ParseRequested $request, string $reason): void
    {
        $this->chunker->discard($this->chunkPrefix($request));

        $this->move($request, [BulkImportStatus::Queued, BulkImportStatus::Parsing], BulkImportStatus::Failed, [
            'failure_message' => Str::limit($reason, self::FAILURE_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * @param  list<array{sequence: int, object_key: string}>  $chunks
     */
    private function queueChunks(ParseRequested $request, array $chunks): void
    {
        DB::transaction(function () use ($request, $chunks): void {
            if (! $this->move($request, [BulkImportStatus::Parsing], BulkImportStatus::Validating)) {
                return;
            }

            foreach ($chunks as $chunk) {
                $row = BulkImportChunk::query()->create([
                    'bulk_import_id' => $request->bulkImportId,
                    'sequence' => $chunk['sequence'],
                    'source_object_key' => $chunk['object_key'],
                ]);

                $this->outbox->record(new ValidateChunk($request->bulkImportId, $row->getKey(), $chunk['object_key']));
            }
        });
    }

    /**
     * Changes the import's status only if it is currently one of $from, and says whether it did.
     * The check and the change are one query, so two workers cannot both win.
     *
     * @param  list<BulkImportStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(ParseRequested $request, array $from, BulkImportStatus $to, array $extra = []): bool
    {
        return BulkImport::query()
            ->whereKey($request->bulkImportId)
            ->whereIn('status', array_map(fn (BulkImportStatus $status): string => $status->value, $from))
            ->update(['status' => $to->value, ...$extra]) > 0;
    }

    /**
     * Chunks live next to the source: consent/import-{id}/source/source.csv
     * becomes consent/import-{id}/chunks.
     */
    private function chunkPrefix(ParseRequested $request): string
    {
        return dirname($request->sourceObjectKey, 2).'/chunks';
    }
}
