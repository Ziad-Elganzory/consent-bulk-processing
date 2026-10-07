<?php

namespace App\Domains\BulkImport\Services;

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Exceptions\UnprocessableFile;
use App\Domains\BulkImport\Messages\AssembleImport;
use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use App\Domains\BulkImport\Services\Validation\RowRules;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Features\RabbitMQ\Publishing\Outbox;
use RuntimeException;

/**
 * Checks every row of one chunk against the schema. Valid rows go to a result file and
 * rejected rows, with the reasons, to an error file; a rejected row never fails the chunk.
 *
 * Safe to run more than once, and by several workers at once, for the same request:
 *  - a chunk that is already completed or failed is skipped;
 *  - result files have fixed names, so a retry overwrites them;
 *  - the chunk's final status and the hand-over to assembly commit together, and the
 *    import row is locked first, so chunks finishing at the same moment take turns and
 *    exactly one of them (the last) queues the assemble message.
 */
final class ChunkValidator
{
    private const int FAILURE_MESSAGE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly RowRules $rules,
        private readonly Outbox $outbox,
    ) {}

    public function validate(ValidateChunk $request): void
    {
        $chunk = BulkImportChunk::query()->find($request->chunkId);

        // Pending: first attempt. Processing: an earlier attempt stopped part way, so redo it.
        // Anything else: this request was already handled.
        if ($chunk === null || ! in_array($chunk->status, [BulkImportChunkStatus::Pending, BulkImportChunkStatus::Processing], true)) {
            return;
        }

        BulkImportChunk::query()
            ->whereKey($chunk->getKey())
            ->where('status', BulkImportChunkStatus::Pending->value)
            ->update(['status' => BulkImportChunkStatus::Processing->value]);

        try {
            $result = $this->checkRows($request->chunkObjectKey);
        } catch (UnprocessableFile $exception) {
            $this->fail($request, $exception->getMessage());

            return;
        }

        $this->finish($request, BulkImportChunkStatus::Completed, $result);
    }

    /**
     * Marks the chunk failed, so the import can still move on to assembly.
     */
    public function fail(ValidateChunk $request, string $reason): void
    {
        $this->finish($request, BulkImportChunkStatus::Failed, [
            'failure_message' => Str::limit($reason, self::FAILURE_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(ValidateChunk $request, BulkImportChunkStatus $status, array $attributes): void
    {
        DB::transaction(function () use ($request, $status, $attributes): void {
            // Locked first: the checks below must run after any chunk that finished before this one.
            BulkImport::query()->whereKey($request->bulkImportId)->lockForUpdate()->first();

            $finished = BulkImportChunk::query()
                ->whereKey($request->chunkId)
                ->whereIn('status', [BulkImportChunkStatus::Pending->value, BulkImportChunkStatus::Processing->value])
                ->update(['status' => $status->value, ...$attributes]);

            if ($finished === 0 || $this->hasUnfinishedChunks($request->bulkImportId)) {
                return;
            }

            $moved = BulkImport::query()
                ->whereKey($request->bulkImportId)
                ->where('status', BulkImportStatus::Validating->value)
                ->update(['status' => BulkImportStatus::Assembling->value]);

            if ($moved > 0) {
                $this->outbox->record(new AssembleImport($request->bulkImportId));
            }
        });
    }

    private function hasUnfinishedChunks(string $bulkImportId): bool
    {
        return BulkImportChunk::query()
            ->where('bulk_import_id', $bulkImportId)
            ->whereNotIn('status', [BulkImportChunkStatus::Completed->value, BulkImportChunkStatus::Failed->value])
            ->exists();
    }

    /**
     * Streams the chunk, writes the valid and the rejected rows to their files and returns
     * what to store on the chunk.
     *
     * @return array{valid_rows: int, invalid_rows: int, result_object_key: string, error_object_key: string|null}
     *
     * @throws UnprocessableFile when the chunk has no usable header
     * @throws RuntimeException when storage fails, which a retry can fix
     */
    private function checkRows(string $chunkKey): array
    {
        $disk = Storage::disk(config('bulk-imports.disk'));
        $source = $disk->readStream($chunkKey);

        if (! is_resource($source)) {
            throw new RuntimeException("Could not read [{$chunkKey}] from storage.");
        }

        $valid = $this->temporaryFile();
        $rejected = $this->temporaryFile();

        try {
            $header = array_map(fn (?string $name): string => trim((string) $name), (array) fgetcsv($source, escape: ''));
            $positions = $this->columnPositions($header);

            fputcsv($valid, $header, escape: '');
            fputcsv($rejected, [...$header, 'Errors'], escape: '');

            $validRows = 0;
            $invalidRows = 0;
            $firstRowOfKey = [];

            for ($rowNumber = 1; ($record = fgetcsv($source, escape: '')) !== false; $rowNumber++) {
                $errors = $this->rowErrors($record, $header, $positions, $rowNumber, $firstRowOfKey);

                if ($errors === []) {
                    fputcsv($valid, $record, escape: '');
                    $validRows++;
                } else {
                    fputcsv($rejected, [...$record, implode('; ', $errors)], escape: '');
                    $invalidRows++;
                }
            }

            return [
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'result_object_key' => $this->store($disk, $valid, $this->siblingKey($chunkKey, 'results')),
                'error_object_key' => $invalidRows > 0 ? $this->store($disk, $rejected, $this->siblingKey($chunkKey, 'errors')) : null,
            ];
        } finally {
            fclose($source);

            foreach ([$valid, $rejected] as $file) {
                if (is_resource($file)) {
                    fclose($file);
                }
            }
        }
    }

    /**
     * @param  list<string|null>  $record
     * @param  list<string>  $header
     * @param  array<string, int>  $positions  schema column => index in the header
     * @param  array<string, int>  $firstRowOfKey  record key => the row that first used it, filled as rows are read
     * @return list<string>
     */
    private function rowErrors(array $record, array $header, array $positions, int $rowNumber, array &$firstRowOfKey): array
    {
        if (count($record) !== count($header)) {
            return ['has '.count($record).' cells but the header has '.count($header)];
        }

        $row = array_map(fn (int $index): string => trim((string) $record[$index]), $positions);
        $errors = $this->rules->errors($row);

        if ($errors !== []) {
            return $errors;
        }

        $key = $this->rules->uniqueKey($row);

        if (isset($firstRowOfKey[$key])) {
            return ["is a duplicate of row {$firstRowOfKey[$key]} of this chunk"];
        }

        $firstRowOfKey[$key] = $rowNumber;

        return [];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     *
     * @throws UnprocessableFile
     */
    private function columnPositions(array $header): array
    {
        $indexByName = array_flip(array_map('strtolower', $header));
        $positions = [];

        foreach ($this->rules->columns() as $column) {
            if (! isset($indexByName[strtolower($column)])) {
                throw UnprocessableFile::because("the chunk header is missing the column [{$column}]");
            }

            $positions[$column] = $indexByName[strtolower($column)];
        }

        return $positions;
    }

    /**
     * consent/import-{id}/chunks/chunk-000001.csv becomes consent/import-{id}/{$folder}/chunk-000001.csv.
     */
    private function siblingKey(string $chunkKey, string $folder): string
    {
        return dirname($chunkKey, 2).'/'.$folder.'/'.basename($chunkKey);
    }

    /**
     * @param  resource  $file
     */
    private function store(Filesystem $disk, $file, string $objectKey): string
    {
        rewind($file);

        if ($disk->writeStream($objectKey, $file) === false) {
            throw new RuntimeException("Could not write [{$objectKey}] to storage.");
        }

        return $objectKey;
    }

    /**
     * @return resource
     */
    private function temporaryFile()
    {
        $file = tmpfile();

        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        return $file;
    }
}
