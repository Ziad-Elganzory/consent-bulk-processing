<?php

namespace App\Domains\BulkImport\Services\Csv;

use App\Domains\BulkImport\Exceptions\UnprocessableFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use RuntimeException;

/**
 * Streams an uploaded CSV from storage and writes it back as numbered chunk files, each
 * starting with the header row. Rows are read one at a time and each chunk is built in a
 * temporary file, so memory stays bounded.
 *
 * Chunk keys are deterministic ({prefix}/chunk-000001.csv, ...), so a retry overwrites the same
 * objects instead of adding new ones.
 */
class CsvChunker
{
    private const int SAMPLE_BYTES = 8192;

    /**
     * @param  list<string>  $requiredColumns  header names the file must contain, in any order and case
     * @return list<array{sequence: int, object_key: string}>
     *
     * @throws UnprocessableFile when the file can never be processed
     * @throws RuntimeException when storage fails, which a retry can fix
     */
    public function split(string $sourceKey, ?string $originalFilename, string $chunkPrefix, array $requiredColumns = []): array
    {
        $disk = Storage::disk(config('bulk-imports.disk'));

        $this->assertProcessable($disk, $sourceKey, $originalFilename);

        $source = $this->open($disk, $sourceKey);
        $chunks = [];
        $chunk = null;
        $rowsInChunk = 0;

        try {
            $header = $this->header($source, $requiredColumns);
            $rowNumber = 1;

            while (($record = $this->nextRecord($source, ++$rowNumber)) !== null) {
                if ($this->isBlank($record)) {
                    continue;
                }

                $chunk ??= $this->newChunk($header);
                fputcsv($chunk, $record, escape: '');
                $rowsInChunk++;

                if ($rowsInChunk >= config('bulk-imports.chunk_max_rows') || ftell($chunk) >= config('bulk-imports.chunk_max_bytes')) {
                    $chunks[] = $this->store($disk, $chunk, $chunkPrefix, count($chunks) + 1);
                    $chunk = null;
                    $rowsInChunk = 0;
                }
            }

            if ($chunk !== null) {
                $chunks[] = $this->store($disk, $chunk, $chunkPrefix, count($chunks) + 1);
                $chunk = null;
            }
        } finally {
            fclose($source);

            if (is_resource($chunk)) {
                fclose($chunk);
            }
        }

        if ($chunks === []) {
            throw UnprocessableFile::because('it has no data rows');
        }

        return $chunks;
    }

    /**
     * Removes every chunk written under $chunkPrefix. Best effort: a leftover chunk is
     * overwritten by the next attempt anyway.
     */
    public function discard(string $chunkPrefix): void
    {
        Storage::disk(config('bulk-imports.disk'))->deleteDirectory($chunkPrefix);
    }

    private function assertProcessable(Filesystem $disk, string $sourceKey, ?string $originalFilename): void
    {
        if (strtolower(pathinfo((string) $originalFilename, PATHINFO_EXTENSION)) !== 'csv') {
            throw UnprocessableFile::because('it is not a .csv file');
        }

        // exists() and size() throw when storage is unreachable, so an outage is retried.
        if (! $disk->exists($sourceKey)) {
            throw UnprocessableFile::because('the uploaded file is missing');
        }

        $size = $disk->size($sourceKey);
        $maxBytes = config('bulk-imports.max_file_size_kb') * 1024;

        if ($size === 0) {
            throw UnprocessableFile::because('it is empty');
        }

        if ($size > $maxBytes) {
            throw UnprocessableFile::because('it is larger than '.Number::fileSize($maxBytes));
        }

        $sample = $this->sample($disk, $sourceKey);

        if (str_contains($sample, "\0")) {
            throw UnprocessableFile::because('it is not a text file');
        }

        if (! $this->isUtf8($sample)) {
            throw UnprocessableFile::because('it is not UTF-8 text');
        }
    }

    /**
     * @param  resource  $source
     * @param  list<string>  $requiredColumns
     * @return list<string>
     */
    private function header($source, array $requiredColumns): array
    {
        $header = $this->nextRecord($source, 1);

        if ($header === null || $this->isBlank($header)) {
            throw UnprocessableFile::because('it has no header row');
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn (?string $name): string => trim((string) $name), $header);
        $seen = [];

        foreach ($header as $index => $name) {
            if ($name === '') {
                throw UnprocessableFile::because('column '.($index + 1).' of the header has no name');
            }

            if (isset($seen[strtolower($name)])) {
                throw UnprocessableFile::because("the header has the column [{$name}] twice");
            }

            $seen[strtolower($name)] = true;
        }

        $missing = array_filter($requiredColumns, fn (string $column): bool => ! isset($seen[strtolower($column)]));

        if ($missing !== []) {
            throw UnprocessableFile::because('the header is missing the column(s) ['.implode(', ', $missing).']');
        }

        return $header;
    }

    /**
     * @param  resource  $source
     * @return list<string|null>|null null at the end of the file
     */
    private function nextRecord($source, int $rowNumber): ?array
    {
        $start = ftell($source);
        $record = fgetcsv($source, escape: '');

        if ($record === false) {
            return null;
        }

        $maxRowBytes = config('bulk-imports.max_row_bytes');

        if ($start !== false && ftell($source) - $start > $maxRowBytes) {
            throw UnprocessableFile::because(
                "row {$rowNumber} is larger than ".Number::fileSize($maxRowBytes).', which usually means a quote is never closed',
            );
        }

        return $record;
    }

    /**
     * @param  list<string|null>  $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $header
     * @return resource
     */
    private function newChunk(array $header)
    {
        $chunk = tmpfile();

        if ($chunk === false) {
            throw new RuntimeException('Could not create a temporary file for a chunk.');
        }

        fputcsv($chunk, $header, escape: '');

        return $chunk;
    }

    /**
     * @param  resource  $chunk
     * @return array{sequence: int, object_key: string}
     */
    private function store(Filesystem $disk, $chunk, string $chunkPrefix, int $sequence): array
    {
        $objectKey = sprintf('%s/chunk-%06d.csv', $chunkPrefix, $sequence);
        rewind($chunk);

        try {
            if ($disk->writeStream($objectKey, $chunk) === false) {
                throw new RuntimeException("Could not write chunk [{$objectKey}] to storage.");
            }
        } finally {
            fclose($chunk);
        }

        return ['sequence' => $sequence, 'object_key' => $objectKey];
    }

    /**
     * @return resource
     */
    private function open(Filesystem $disk, string $sourceKey)
    {
        $stream = $disk->readStream($sourceKey);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$sourceKey}] from storage.");
        }

        return $stream;
    }

    private function sample(Filesystem $disk, string $sourceKey): string
    {
        $stream = $this->open($disk, $sourceKey);

        try {
            return (string) fread($stream, self::SAMPLE_BYTES);
        } finally {
            fclose($stream);
        }
    }

    private function isUtf8(string $sample): bool
    {
        // The sample can end in the middle of a multi-byte character, so allow up to 3 cut-off bytes.
        for ($cut = 0; $cut <= 3; $cut++) {
            if (mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }
}
