<?php

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Handlers\AssembleImportHandler;
use App\Domains\BulkImport\Handlers\ParseImportHandler;
use App\Domains\BulkImport\Handlers\ValidateChunkHandler;
use App\Domains\BulkImport\Messages\AssembleImport;
use App\Domains\BulkImport\Messages\ParseRequested;
use App\Domains\BulkImport\Messages\ValidateChunk;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Core\Features\RabbitMQ\Models\OutboxMessage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('bulk-imports.disk'));
});

function assemblingImport(): BulkImport
{
    $import = BulkImport::factory()->create(['status' => BulkImportStatus::Assembling, 'original_filename' => 'consents.csv']);
    $import->update(['source_object_key' => "consent/import-{$import->getKey()}/source/source.csv"]);

    return $import->refresh();
}

function validatedChunk(BulkImport $import, int $sequence, string $result, ?string $errors = null, BulkImportChunkStatus $status = BulkImportChunkStatus::Completed): BulkImportChunk
{
    $disk = Storage::disk(config('bulk-imports.disk'));
    $resultKey = "consent/import-{$import->getKey()}/results/chunk-{$sequence}.csv";
    $errorKey = $errors === null ? null : "consent/import-{$import->getKey()}/errors/chunk-{$sequence}.csv";

    if ($status === BulkImportChunkStatus::Completed) {
        $disk->put($resultKey, $result);
    }

    if ($errorKey !== null) {
        $disk->put($errorKey, $errors);
    }

    return BulkImportChunk::factory()->create([
        'bulk_import_id' => $import->getKey(),
        'sequence' => $sequence,
        'status' => $status,
        'invalid_rows' => $errors === null ? 0 : 1,
        'result_object_key' => $status === BulkImportChunkStatus::Completed ? $resultKey : null,
        'error_object_key' => $errorKey,
    ]);
}

function assembleImport(BulkImport $import): void
{
    app(AssembleImportHandler::class)->handle(Envelope::wrap(new AssembleImport($import->getKey())));
}

function storedFile(?string $key): ?string
{
    return $key === null ? null : Storage::disk(config('bulk-imports.disk'))->get($key);
}

it('merges the chunk results in sequence order under one header, and finishes with errors when rows were rejected', function (): void {
    $import = assemblingImport();
    validatedChunk($import, 2, "h1,h2\nc,2\n", "h1,h2,Errors\nbad,2,oops\n");
    validatedChunk($import, 1, "h1,h2\na,1\nb,1\n");

    assembleImport($import);

    $import->refresh();
    expect($import->status)->toBe(BulkImportStatus::CompletedWithErrors)
        ->and($import->completed_at)->not->toBeNull()
        ->and($import->output_object_key)->toBe("consent/import-{$import->getKey()}/output/result.csv")
        ->and(storedFile($import->output_object_key))->toBe("h1,h2\na,1\nb,1\nc,2\n")
        ->and(storedFile($import->error_object_key))->toBe("h1,h2,Errors\nbad,2,oops\n");
});

it('finishes completed, without an error file, when no row was rejected', function (): void {
    $import = assemblingImport();
    validatedChunk($import, 1, "h1,h2\na,1\n");

    assembleImport($import);

    $import->refresh();
    expect($import->status)->toBe(BulkImportStatus::Completed)
        ->and($import->error_object_key)->toBeNull()
        ->and($import->failure_message)->toBeNull();
});

it('finishes with errors and names the chunks that could not be validated', function (): void {
    $import = assemblingImport();
    validatedChunk($import, 1, "h1,h2\na,1\n");
    validatedChunk($import, 2, '', status: BulkImportChunkStatus::Failed);
    validatedChunk($import, 3, "h1,h2\nc,3\n");

    assembleImport($import);

    $import->refresh();
    expect($import->status)->toBe(BulkImportStatus::CompletedWithErrors)
        ->and($import->failure_message)->toBe('Chunks that could not be validated: 2')
        ->and(storedFile($import->output_object_key))->toBe("h1,h2\na,1\nc,3\n");
});

it('has no result file when every chunk failed', function (): void {
    $import = assemblingImport();
    validatedChunk($import, 1, '', status: BulkImportChunkStatus::Failed);

    assembleImport($import);

    expect($import->refresh()->status)->toBe(BulkImportStatus::CompletedWithErrors)
        ->and($import->output_object_key)->toBeNull();
});

it('ignores an import that is not assembling, and handles a duplicate delivery only once', function (): void {
    $import = assemblingImport();
    validatedChunk($import, 1, "h1,h2\na,1\n");
    assembleImport($import);
    $completedAt = $import->refresh()->completed_at;

    $this->travel(5)->minutes();
    assembleImport($import);

    expect($import->refresh()->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($import->status)->toBe(BulkImportStatus::Completed);
});

it('marks the import failed once its attempts are used up', function (): void {
    $import = assemblingImport();

    app(AssembleImportHandler::class)->failed(Envelope::wrap(new AssembleImport($import->getKey())), new RuntimeException('MinIO is down'));

    expect($import->refresh()->status)->toBe(BulkImportStatus::Failed)
        ->and($import->failure_message)->toBe('Assembling failed: MinIO is down');
});

it('takes an uploaded file through parse, validate and assemble', function (): void {
    config(['bulk-imports.chunk_max_rows' => 2]);
    $import = BulkImport::factory()->create(['status' => BulkImportStatus::Queued, 'original_filename' => 'consents.csv']);
    $sourceKey = "consent/import-{$import->getKey()}/source/source.csv";
    Storage::disk(config('bulk-imports.disk'))->put($sourceKey, implode("\n", [
        'Consent code,Consent name,Description,Purpose,Version,Status',
        'A,Name A,,Marketing,1,Active',
        'A,Name A,,Marketing,2,Active',
        ',Name B,,Marketing,1,Active',
        'C,Name C,,Marketing,1,Inactive',
        'D,Name D,,Marketing,1,Active',
    ])."\n");

    app(ParseImportHandler::class)->handle(Envelope::wrap(new ParseRequested($import->getKey(), $sourceKey)));

    OutboxMessage::query()->where('routing_key', config('bulk-imports.messaging.routing_keys.validate_chunk'))->get()
        ->each(fn (OutboxMessage $message) => app(ValidateChunkHandler::class)->handle(Envelope::wrap(ValidateChunk::fromPayload($message->payload))));

    $assemble = OutboxMessage::query()->where('routing_key', config('bulk-imports.messaging.routing_keys.assemble_import'))->sole();
    app(AssembleImportHandler::class)->handle(Envelope::wrap(AssembleImport::fromPayload($assemble->payload)));

    $import->refresh();
    expect($import->status)->toBe(BulkImportStatus::CompletedWithErrors)
        ->and(storedFile($import->output_object_key))->toBe("\"Consent code\",\"Consent name\",Description,Purpose,Version,Status\nA,\"Name A\",,Marketing,1,Active\nA,\"Name A\",,Marketing,2,Active\nC,\"Name C\",,Marketing,1,Inactive\nD,\"Name D\",,Marketing,1,Active\n")
        ->and(storedFile($import->error_object_key))->toContain('Consent code is required');
});
