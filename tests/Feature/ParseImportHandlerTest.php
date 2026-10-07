<?php

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Handlers\ParseImportHandler;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use App\Domains\BulkImport\Services\Csv\CsvChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Models\OutboxMessage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('bulk-imports.disk'));
});

function importWithCsv(?string $contents, string $filename = 'consents.csv', BulkImportStatus $status = BulkImportStatus::Queued): BulkImport
{
    $import = BulkImport::factory()->create(['status' => $status, 'original_filename' => $filename]);
    $sourceKey = "consent/import-{$import->getKey()}/source/source.csv";

    if ($contents !== null) {
        Storage::disk(config('bulk-imports.disk'))->put($sourceKey, $contents);
    }

    $import->update(['source_object_key' => $sourceKey]);

    return $import->refresh();
}

function parseEnvelope(BulkImport $import): Envelope
{
    return Envelope::wrap(new ParseRequested($import->getKey(), $import->source_object_key));
}

function parseImport(BulkImport $import, ?Envelope $envelope = null): void
{
    app(ParseImportHandler::class)->handle($envelope ?? parseEnvelope($import));
}

function chunkKey(BulkImport $import, int $sequence): string
{
    return sprintf('consent/import-%s/chunks/chunk-%06d.csv', $import->getKey(), $sequence);
}

function chunkContents(BulkImport $import, int $sequence): ?string
{
    return Storage::disk(config('bulk-imports.disk'))->get(chunkKey($import, $sequence));
}

it('splits the file into chunks that each start with the header, and queues one validation message per chunk', function (): void {
    config(['bulk-imports.chunk_max_rows' => 2]);
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n2,b\n3,c\n");

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating)
        ->and(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n1,a\n2,b\n")
        ->and(chunkContents($import, 2))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n3,c\n")
        ->and(chunkContents($import, 3))->toBeNull();

    $chunks = BulkImportChunk::query()->orderBy('sequence')->get();
    $messages = OutboxMessage::query()->get();

    expect($chunks->pluck('sequence')->all())->toBe([1, 2])
        ->and($chunks->pluck('source_object_key')->all())->toBe([chunkKey($import, 1), chunkKey($import, 2)])
        ->and($messages)->toHaveCount(2)
        ->and($messages->pluck('exchange')->unique()->all())->toBe([config('bulk-imports.messaging.exchange')])
        ->and($messages->pluck('routing_key')->unique()->all())->toBe([config('bulk-imports.messaging.routing_keys.validate_chunk')])
        ->and($messages->pluck('payload.chunk_id')->sort()->values()->all())->toBe($chunks->pluck('id')->sort()->values()->all())
        ->and($messages->pluck('payload.bulk_import_id')->unique()->all())->toBe([$import->getKey()]);
});

it('starts a new chunk when the byte limit is reached', function (): void {
    config(['bulk-imports.chunk_max_bytes' => 73]);
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n2,b\n3,c\n");

    parseImport($import);

    expect(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n1,a\n2,b\n")
        ->and(chunkContents($import, 2))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n3,c\n");
});

it('keeps a quoted field with a newline in one row', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\nA,\"line1\nline2\"\nB,x\n");

    parseImport($import);

    expect(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\nA,\"line1\nline2\"\nB,x\n");
});

it('strips a byte order mark and skips blank rows', function (): void {
    $import = importWithCsv("\xEF\xBB\xBFConsent code,Consent name,Description,Purpose,Version,Status\n\n1,a\n,\n2,b\n");

    parseImport($import);

    expect(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n1,a\n2,b\n");
});

it('passes rows with the wrong number of columns on to validation', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1\n1,2,3\n");

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating)
        ->and(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n1\n1,2,3\n");
});

it('handles a duplicate delivery only once', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n");
    $envelope = parseEnvelope($import);

    parseImport($import, $envelope);
    parseImport($import, $envelope);

    expect(BulkImportChunk::query()->count())->toBe(1)
        ->and(OutboxMessage::query()->count())->toBe(1);
});

it('creates nothing when another worker finished the import while this one was parsing', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n");
    $this->mock(CsvChunker::class, fn (MockInterface $mock) => $mock->shouldReceive('split')->andReturnUsing(function () use ($import): array {
        // The other worker wins the race to validating while this one is still splitting the file.
        $import->update(['status' => BulkImportStatus::Validating]);

        return [['sequence' => 1, 'object_key' => chunkKey($import, 1)]];
    }));

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating)
        ->and(BulkImportChunk::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('finishes an import that an earlier attempt left in parsing, overwriting its chunk files', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n", status: BulkImportStatus::Parsing);
    Storage::disk(config('bulk-imports.disk'))->put(chunkKey($import, 1), 'left over from a crashed attempt');

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating)
        ->and(chunkContents($import, 1))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\n1,a\n")
        ->and(BulkImportChunk::query()->count())->toBe(1);
});

it('skips an import that is already past parsing', function (BulkImportStatus $status): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n", status: $status);

    parseImport($import);

    expect($import->refresh()->status)->toBe($status)
        ->and(BulkImportChunk::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
})->with([BulkImportStatus::Validating, BulkImportStatus::Completed, BulkImportStatus::Failed]);

it('ignores a message for an import that no longer exists', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n");
    $envelope = parseEnvelope($import);
    $import->delete();

    parseImport($import, $envelope);

    expect(BulkImportChunk::query()->count())->toBe(0);
});

it('fails the import without retrying when the file is not processable', function (?string $contents, string $filename, string $reason): void {
    $import = importWithCsv($contents, $filename);
    Storage::disk(config('bulk-imports.disk'))->put(chunkKey($import, 1), 'stale chunk');

    parseImport($import);

    $import->refresh();
    expect($import->status)->toBe(BulkImportStatus::Failed)
        ->and($import->failure_message)->toStartWith('The file is not processable:')
        ->and($import->failure_message)->toContain($reason)
        ->and(chunkContents($import, 1))->toBeNull()
        ->and(BulkImportChunk::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
})->with([
    'missing file' => [null, 'consents.csv', 'the uploaded file is missing'],
    'empty file' => ['', 'consents.csv', 'it is empty'],
    'not a csv file' => ["a,b\n1,2\n", 'consents.xlsx', 'not a .csv file'],
    'binary content' => ["a,b\n\0\0\0", 'consents.csv', 'not a text file'],
    'not utf-8' => ["a,b\n\xC3\x28,x\nmore,rows\n", 'consents.csv', 'not UTF-8 text'],
    'no header row' => ["\n\n", 'consents.csv', 'no header row'],
    'header only' => ["Consent code,Consent name,Description,Purpose,Version,Status\n", 'consents.csv', 'no data rows'],
    'only blank rows' => ["Consent code,Consent name,Description,Purpose,Version,Status\n,\n\n", 'consents.csv', 'no data rows'],
    'missing required columns' => ["a,b\n1,2\n", 'consents.csv', 'the header is missing the column(s) [Consent code, Consent name'],
    'duplicate column' => ["a,A\n1,2\n", 'consents.csv', 'the column [A] twice'],
    'unnamed column' => ["a,,c\n1,2,3\n", 'consents.csv', 'column 2 of the header has no name'],
]);

it('fails the import when the file is larger than the limit', function (): void {
    config(['bulk-imports.max_file_size_kb' => 1]);
    $import = importWithCsv("a,b\n".str_repeat("1,2\n", 600));

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Failed)
        ->and($import->failure_message)->toContain('larger than');
});

it('fails the import when a row is larger than the limit, which usually means an unclosed quote', function (): void {
    config(['bulk-imports.max_row_bytes' => 80]);
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n\"never closed,".str_repeat('x', 100)."\n1,2\n");

    parseImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Failed)
        ->and($import->failure_message)->toContain('row 2 is larger than');
});

it('lets a storage failure be retried, leaving the import in parsing', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n");
    $this->mock(CsvChunker::class, fn (MockInterface $mock) => $mock->shouldReceive('split')->andThrow(new RuntimeException('MinIO is down')));

    expect(fn () => parseImport($import))->toThrow(RuntimeException::class, 'MinIO is down');

    expect($import->refresh()->status)->toBe(BulkImportStatus::Parsing);
});

it('marks the import failed and removes its chunks when the last attempt fails', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n", status: BulkImportStatus::Parsing);
    Storage::disk(config('bulk-imports.disk'))->put(chunkKey($import, 1), 'partial chunk');

    app(ParseImportHandler::class)->failed(parseEnvelope($import), new RuntimeException('MinIO is down'));

    expect($import->refresh()->status)->toBe(BulkImportStatus::Failed)
        ->and($import->failure_message)->toBe('Parsing failed: MinIO is down')
        ->and(chunkContents($import, 1))->toBeNull();
});

it('does not fail an import that already finished parsing', function (): void {
    $import = importWithCsv("Consent code,Consent name,Description,Purpose,Version,Status\n1,a\n", status: BulkImportStatus::Validating);

    app(ParseImportHandler::class)->failed(parseEnvelope($import), new RuntimeException('late failure'));

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating);
});
