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
 * Combines the validated chunks of an import into one result file and one error file,
 * and sets the import's final status.
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
        $resultKeys = [];
        $errorKeys = [];
        $failedSequences = [];
        $hasRejectedRows = false;

        $chunks = BulkImportChunk::query()
            ->where('bulk_import_id', $import->getKey())
            ->orderBy('sequence')
            ->get(['sequence', 'status', 'invalid_rows', 'result_object_key', 'error_object_key']);

        foreach ($chunks as $chunk) {
            if ($chunk->status === BulkImportChunkStatus::Failed) {
                $failedSequences[] = $chunk->sequence;

                continue;
            }

            $resultKeys[] = $chunk->result_object_key;
            $hasRejectedRows = $hasRejectedRows || $chunk->invalid_rows > 0;

            if ($chunk->error_object_key !== null) {
                $errorKeys[] = $chunk->error_object_key;
            }
        }

        $outputFolder = dirname($import->source_object_key, 2).'/output';
        $resultKey = $resultKeys === [] ? null : $this->merge($disk, $resultKeys, "{$outputFolder}/result.csv");
        $errorKey = $errorKeys === [] ? null : $this->merge($disk, $errorKeys, "{$outputFolder}/errors.csv");

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
     * Joins the files into one, keeping the header of the first. Each file is streamed, so
     * memory stays bounded however many rows there are.
     *
     * @param  list<string>  $sourceKeys
     */
    private function merge(Filesystem $disk, array $sourceKeys, string $targetKey): string
    {
        $merged = tmpfile();

        if ($merged === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        try {
            foreach ($sourceKeys as $position => $sourceKey) {
                $source = $disk->readStream($sourceKey);

                if (! is_resource($source)) {
                    throw new RuntimeException("Could not read [{$sourceKey}] from storage.");
                }

                try {
                    $header = (string) fgets($source);

                    if ($position === 0) {
                        fwrite($merged, $header);
                    }

                    stream_copy_to_stream($source, $merged);
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
}
