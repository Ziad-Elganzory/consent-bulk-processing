<?php

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Handlers\ValidateChunkHandler;
use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Models\OutboxMessage;

uses(RefreshDatabase::class);

const CONSENT_HEADER = 'Consent code,Consent name,Description,Purpose,Version,Status';

beforeEach(function (): void {
    Storage::fake(config('bulk-imports.disk'));
});

/**
 * @param  list<string>  $rows
 */
function chunkWithRows(array $rows, ?BulkImport $import = null, int $sequence = 1): BulkImportChunk
{
    $import ??= BulkImport::factory()->create(['status' => BulkImportStatus::Validating]);
    $key = sprintf('consent/import-%s/chunks/chunk-%06d.csv', $import->getKey(), $sequence);
    Storage::disk(config('bulk-imports.disk'))->put($key, implode("\n", [CONSENT_HEADER, ...$rows])."\n");

    return BulkImportChunk::factory()->create([
        'bulk_import_id' => $import->getKey(),
        'sequence' => $sequence,
        'source_object_key' => $key,
    ]);
}

function validateEnvelope(BulkImportChunk $chunk): Envelope
{
    return Envelope::wrap(new ValidateChunk($chunk->bulk_import_id, $chunk->getKey(), $chunk->source_object_key));
}

function validateChunk(BulkImportChunk $chunk, ?Envelope $envelope = null): void
{
    app(ValidateChunkHandler::class)->handle($envelope ?? validateEnvelope($chunk));
}

function stored(?string $key): ?string
{
    return $key === null ? null : Storage::disk(config('bulk-imports.disk'))->get($key);
}

it('writes only the rejected rows, with their position and reasons, to an error file', function (): void {
    $chunk = chunkWithRows([
        'A,Name A,,Purpose,1,Active',
        ',Name B,,Purpose,1,Active',
        'C,Name C,,Purpose,x,Active',
    ]);

    validateChunk($chunk);

    $chunk->refresh();
    expect($chunk->status)->toBe(BulkImportChunkStatus::Completed)
        ->and($chunk->valid_rows)->toBe(1)
        ->and($chunk->invalid_rows)->toBe(2)
        ->and($chunk->error_object_key)->toBe("consent/import-{$chunk->bulk_import_id}/errors/chunk-000001.csv")
        ->and(Storage::disk(config('bulk-imports.disk'))->allFiles("consent/import-{$chunk->bulk_import_id}"))->toHaveCount(2)
        ->and(stored($chunk->error_object_key))->toStartWith('Chunk,"Row in chunk",')
        ->and(stored($chunk->error_object_key))->toContain('1,2,,"Name B"')
        ->and(stored($chunk->error_object_key))->toContain('Consent code is required')
        ->and(stored($chunk->error_object_key))->toContain('Version must be a positive whole number');
});

it('does not write an error file when every row is valid', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active', 'A,Name A,,Purpose,2,Active']);

    validateChunk($chunk);

    expect($chunk->refresh()->error_object_key)->toBeNull()
        ->and($chunk->valid_rows)->toBe(2)
        ->and($chunk->invalid_rows)->toBe(0);
});

it('rejects a second row with the same code and version, but not another version of the code', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active', 'A,Name A,,Purpose,1,Active', 'A,Name A,,Purpose,2,Active']);

    validateChunk($chunk);

    expect($chunk->refresh()->valid_rows)->toBe(2)
        ->and($chunk->invalid_rows)->toBe(1)
        ->and(stored($chunk->error_object_key))->toContain('is a duplicate of row 1 of this chunk');
});

it('rejects a row with the wrong number of cells', function (): void {
    $chunk = chunkWithRows(['A,Name A']);

    validateChunk($chunk);

    expect($chunk->refresh()->invalid_rows)->toBe(1)
        ->and(stored($chunk->error_object_key))->toContain('has 2 cells but the header has 6');
});

it('queues one assemble message only when the last chunk finishes, and moves the import on', function (): void {
    $import = BulkImport::factory()->create(['status' => BulkImportStatus::Validating]);
    $first = chunkWithRows(['A,Name A,,Purpose,1,Active'], $import, 1);
    $second = chunkWithRows(['B,Name B,,Purpose,1,Active'], $import, 2);

    validateChunk($first);

    expect($import->refresh()->status)->toBe(BulkImportStatus::Validating)
        ->and(OutboxMessage::query()->count())->toBe(0);

    validateChunk($second);

    $outbox = OutboxMessage::query()->sole();
    expect($import->refresh()->status)->toBe(BulkImportStatus::Assembling)
        ->and($outbox->routing_key)->toBe(config('bulk-imports.messaging.routing_keys.assemble_import'))
        ->and($outbox->payload)->toBe(['bulk_import_id' => $import->getKey()]);
});

it('handles a duplicate delivery only once', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active']);
    $envelope = validateEnvelope($chunk);

    validateChunk($chunk, $envelope);
    validateChunk($chunk, $envelope);

    expect(OutboxMessage::query()->count())->toBe(1)
        ->and($chunk->refresh()->status)->toBe(BulkImportChunkStatus::Completed);
});

it('finishes a chunk that an earlier attempt left in processing', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active']);
    $chunk->update(['status' => BulkImportChunkStatus::Processing]);

    validateChunk($chunk);

    expect($chunk->refresh()->status)->toBe(BulkImportChunkStatus::Completed);
});

it('ignores a message for a chunk that no longer exists', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active']);
    $envelope = validateEnvelope($chunk);
    $chunk->delete();

    validateChunk($chunk, $envelope);

    expect(OutboxMessage::query()->count())->toBe(0);
});

it('fails the chunk without retrying when its header lacks a schema column', function (): void {
    $chunk = chunkWithRows([]);
    Storage::disk(config('bulk-imports.disk'))->put($chunk->source_object_key, "a,b\n1,2\n");

    validateChunk($chunk);

    expect($chunk->refresh()->status)->toBe(BulkImportChunkStatus::Failed)
        ->and($chunk->failure_message)->toContain('missing the column [Consent code]');
});

it('lets a storage failure be retried, leaving the chunk in processing', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active']);
    Storage::disk(config('bulk-imports.disk'))->delete($chunk->source_object_key);

    expect(fn () => validateChunk($chunk))->toThrow(RuntimeException::class);

    expect($chunk->refresh()->status)->toBe(BulkImportChunkStatus::Processing);
});

it('marks the chunk failed once its attempts are used up, and still lets the import move on', function (): void {
    $chunk = chunkWithRows(['A,Name A,,Purpose,1,Active']);

    app(ValidateChunkHandler::class)->failed(validateEnvelope($chunk), new RuntimeException('MinIO is down'));

    expect($chunk->refresh()->status)->toBe(BulkImportChunkStatus::Failed)
        ->and($chunk->failure_message)->toBe('Validation failed: MinIO is down')
        ->and(BulkImport::query()->find($chunk->bulk_import_id)->status)->toBe(BulkImportStatus::Assembling)
        ->and(OutboxMessage::query()->count())->toBe(1);
});
