<?php

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use App\Filament\Resources\BulkImports\Pages\ListBulkImports;
use App\Filament\Resources\BulkImports\Pages\ViewBulkImport;
use App\Filament\Resources\BulkImports\RelationManagers\ChunksRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('bulk-imports.disk'));

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function importFor(User $user, BulkImportStatus $status, array $attributes = []): BulkImport
{
    return BulkImport::factory()->create([
        'user_id' => $user->id,
        'status' => $status,
        'original_filename' => 'consents.csv',
        ...$attributes,
    ]);
}

it('works out progress from the status and the chunks that are done', function (BulkImportStatus $status, int $finished, int $expected): void {
    $import = importFor($this->user, $status);
    BulkImportChunk::factory()->count($finished)->sequence(fn ($sequence) => ['sequence' => $sequence->index + 1])
        ->create(['bulk_import_id' => $import->getKey(), 'status' => BulkImportChunkStatus::Completed]);
    BulkImportChunk::factory()->create(['bulk_import_id' => $import->getKey(), 'sequence' => 99, 'status' => BulkImportChunkStatus::Pending]);

    expect($import->refresh()->progressPercent())->toBe($expected);
})->with([
    'queued' => [BulkImportStatus::Queued, 0, 0],
    'parsing' => [BulkImportStatus::Parsing, 0, 5],
    'validating, nothing done' => [BulkImportStatus::Validating, 0, 10],
    'validating, half done' => [BulkImportStatus::Validating, 1, 50],
    'assembling' => [BulkImportStatus::Assembling, 1, 95],
    'completed' => [BulkImportStatus::Completed, 1, 100],
    'failed' => [BulkImportStatus::Failed, 0, 100],
]);

it('shows a progress column and a view action in the table', function (): void {
    $import = importFor($this->user, BulkImportStatus::Validating);

    Livewire::test(ListBulkImports::class)
        ->assertCanSeeTableRecords([$import])
        ->assertTableColumnExists('progress')
        ->assertSee('role="progressbar"', escape: false)
        ->assertTableActionExists('view');
});

it('shows the import, its progress and its chunks on the view page', function (): void {
    $import = importFor($this->user, BulkImportStatus::Validating);
    $chunks = collect([1, 2])->map(fn (int $sequence) => BulkImportChunk::factory()->create([
        'bulk_import_id' => $import->getKey(),
        'sequence' => $sequence,
        'status' => $sequence === 1 ? BulkImportChunkStatus::Completed : BulkImportChunkStatus::Processing,
        'valid_rows' => 90,
        'invalid_rows' => 10,
    ]));

    Livewire::test(ViewBulkImport::class, ['record' => $import->getKey()])
        ->assertSee('consents.csv')
        ->assertSee('role="progressbar"', escape: false)
        ->assertSee('1 of 2');

    Livewire::test(ChunksRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewBulkImport::class])
        ->assertCanSeeTableRecords($chunks)
        ->assertTableColumnStateSet('status', BulkImportChunkStatus::Completed, $chunks->first())
        ->assertTableColumnStateSet('invalid_rows', 10, $chunks->first());
});

it('offers the downloads only when their files exist, and sends the files', function (): void {
    $disk = Storage::disk(config('bulk-imports.disk'));
    $disk->put('consent/import-x/output/result.csv', "h1\na\n");
    $disk->put('consent/import-x/output/errors.csv', "h1,Errors\nb,oops\n");
    $import = importFor($this->user, BulkImportStatus::CompletedWithErrors, [
        'output_object_key' => 'consent/import-x/output/result.csv',
        'error_object_key' => 'consent/import-x/output/errors.csv',
    ]);

    Livewire::test(ViewBulkImport::class, ['record' => $import->getKey()])
        ->assertActionVisible('downloadResult')
        ->assertActionVisible('downloadRejectedRows')
        ->callAction('downloadResult')
        ->assertFileDownloaded('consents-result.csv', "h1\na\n");

    Livewire::test(ViewBulkImport::class, ['record' => $import->getKey()])
        ->callAction('downloadRejectedRows')
        ->assertFileDownloaded('consents-rejected-rows.csv', "h1,Errors\nb,oops\n");
});

it('hides the downloads while the import has no files', function (): void {
    $import = importFor($this->user, BulkImportStatus::Validating);

    Livewire::test(ViewBulkImport::class, ['record' => $import->getKey()])
        ->assertActionHidden('downloadResult')
        ->assertActionHidden('downloadRejectedRows');
});

it('does not show another user\'s import', function (): void {
    $import = importFor(User::factory()->create(), BulkImportStatus::Completed);

    Livewire::test(ViewBulkImport::class, ['record' => $import->getKey()])->assertNotFound();
});
