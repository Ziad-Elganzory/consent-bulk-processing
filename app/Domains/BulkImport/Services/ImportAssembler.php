<?php

namespace App\Domains\BulkImport\Services;

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Messages\AssembleImport;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the import's result file from its chunk files, leaving out the rejected rows,
 * joins the chunks' error files into one, and sets the import's final status.
 *
 * Safe to run more than once for the same request: the output files have fixed names, so
 * a retry overwrites them, and only an import still assembling is finished, once.
 */
final class ImportAssembler
{
    private const int FAILURE_MESSAGE_MAX_LENGTH = 1000;

    public function assemble(AssembleImport $request): void
    {
        $import = BulkImport::query()->find($request->bulkImportId);

        if ($import === null || $import->status !== BulkImportStatus::Assembling) {
            return;
        }

        $disk = Storage::disk(config('bulk-imports.disk'));
        $resultSources = [];
        $errorSources = [];
        $failedSequences = [];
        $hasRejectedRows = false;

        $chunks = BulkImportChunk::query()
            ->where('bulk_import_id', $import->getKey())
            ->orderBy('sequence')
            ->get(['sequence', 'status', 'invalid_rows', 'source_object_key', 'error_object_key']);

        foreach ($chunks as $chunk) {
            if ($chunk->status === BulkImportChunkStatus::Failed) {
                $failedSequences[] = $chunk->sequence;

                continue;
            }

            $resultSources[$chunk->source_object_key] = $chunk->error_object_key === null ? [] : $this->rejectedRowNumbers($disk, $chunk->error_object_key);
            $hasRejectedRows = $hasRejectedRows || $chunk->invalid_rows > 0;

            if ($chunk->error_object_key !== null) {
                $errorSources[$chunk->error_object_key] = [];
            }
        }

        $outputFolder = dirname($import->source_object_key, 2).'/output';
        $resultKey = $resultSources === [] ? null : $this->merge($disk, $resultSources, "{$outputFolder}/result.csv");
        $errorKey = $errorSources === [] ? null : $this->merge($disk, $errorSources, "{$outputFolder}/errors.csv");

        BulkImport::query()
            ->whereKey($import->getKey())
            ->where('status', BulkImportStatus::Assembling->value)
            ->update([
                'status' => ($hasRejectedRows || $failedSequences !== [] ? BulkImportStatus::CompletedWithErrors : BulkImportStatus::Completed)->value,
                'output_object_key' => $resultKey,
                'error_object_key' => $errorKey,
                'failure_message' => $failedSequences === [] ? null : Str::limit('Chunks that could not be validated: '.implode(', ', $failedSequences), self::FAILURE_MESSAGE_MAX_LENGTH),
                'completed_at' => now(),
            ]);
    }

    /**
     * Marks the import failed, once the assemble message has used up its attempts.
     */
    public function fail(AssembleImport $request, string $reason): void
    {
        BulkImport::query()
            ->whereKey($request->bulkImportId)
            ->where('status', BulkImportStatus::Assembling->value)
            ->update([
                'status' => BulkImportStatus::Failed->value,
                'failure_message' => Str::limit($reason, self::FAILURE_MESSAGE_MAX_LENGTH),
            ]);
    }

    /**
     * The numbers of the chunk's rows that validation rejected, read from its error file.
     *
     * @return list<int>
     */
    private function rejectedRowNumbers(Filesystem $disk, string $errorKey): array
    {
        $source = $disk->readStream($errorKey);

        if (! is_resource($source)) {
            throw new RuntimeException("Could not read [{$errorKey}] from storage.");
        }

        try {
            fgetcsv($source, escape: '');
            $rows = [];

            while (($record = fgetcsv($source, escape: '')) !== false) {
                $rows[] = (int) $record[ChunkValidator::ERROR_FILE_ROW_POSITION];
            }

            return $rows;
        } finally {
            fclose($source);
        }
    }

    /**
     * Joins the files into one, keeping the header of the first and leaving out the listed
     * rows of each file. Each file is streamed, so memory stays bounded however many rows
     * there are.
     *
     * @param  array<string, list<int>>  $sources  object key => numbers of the rows to leave out
     */
    private function merge(Filesystem $disk, array $sources, string $targetKey): string
    {
        $merged = tmpfile();

        if ($merged === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        try {
            $first = true;

            foreach ($sources as $sourceKey => $skippedRows) {
                $source = $disk->readStream($sourceKey);

                if (! is_resource($source)) {
                    throw new RuntimeException("Could not read [{$sourceKey}] from storage.");
                }

                try {
                    $header = (string) fgets($source);

                    if ($first) {
                        fwrite($merged, $header);
                        $first = false;
                    }

                    $this->copyRows($source, $merged, array_flip($skippedRows));
                } finally {
                    fclose($source);
                }
            }

            rewind($merged);

            if ($disk->writeStream($targetKey, $merged) === false) {
                throw new RuntimeException("Could not write [{$targetKey}] to storage.");
            }
        } finally {
            fclose($merged);
        }

        return $targetKey;
    }

    /**
     * @param  resource  $source
     * @param  resource  $target
     * @param  array<int, int>  $skippedRows  row numbers as keys, counted from 1 after the header
     */
    private function copyRows($source, $target, array $skippedRows): void
    {
        if ($skippedRows === []) {
            stream_copy_to_stream($source, $target);

            return;
        }

        for ($rowNumber = 1; ($record = fgetcsv($source, escape: '')) !== false; $rowNumber++) {
            if (! isset($skippedRows[$rowNumber])) {
                fputcsv($target, $record, escape: '');
            }
        }
    }
}
