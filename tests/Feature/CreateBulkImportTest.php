<?php

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Filament\Resources\BulkImports\Pages\CreateBulkImport;
use App\Filament\Resources\BulkImports\Pages\ListBulkImports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('s3');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('creates a queued import owned by the user and writes an outbox message', function (): void {
    $file = UploadedFile::fake()->create('consents.csv', 10, 'text/csv');

    $page = Livewire::test(CreateBulkImport::class)
        ->fillForm(['source_object_key' => $file])
        ->call('create')
        ->assertHasNoFormErrors();

    $import = BulkImport::query()->sole();

    expect($import->user_id)->toBe($this->user->id)
        ->and($import->status)->toBe(BulkImportStatus::Queued)
        ->and($import->original_filename)->toBe('consents.csv')
        ->and($import->getKey())->toBe($page->instance()->uploadId);

    expect($import->source_object_key)->toBe("consent/import-{$import->getKey()}/source/source.csv");

    Storage::disk('s3')->assertExists($import->source_object_key);

    $outbox = DB::table('outbox_messages')->sole();

    expect($outbox->routing_key)->toBe('consent.parse.requested')
        ->and($outbox->published_at)->toBeNull()
        ->and(json_decode($outbox->payload, true))->toBe([
            'bulk_import_id' => $import->getKey(),
            'source_object_key' => $import->source_object_key,
        ]);
});

it('rejects files that are not csv', function (): void {
    $file = UploadedFile::fake()->create('consents.pdf', 10, 'application/pdf');

    Livewire::test(CreateBulkImport::class)
        ->fillForm(['source_object_key' => $file])
        ->call('create')
        ->assertHasFormErrors(['source_object_key']);

    expect(BulkImport::query()->count())->toBe(0)
        ->and(DB::table('outbox_messages')->count())->toBe(0);
});

it('only lists imports owned by the current user', function (): void {
    $own = BulkImport::factory()->create(['user_id' => $this->user->id]);
    $others = BulkImport::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::test(ListBulkImports::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$others]);
});
