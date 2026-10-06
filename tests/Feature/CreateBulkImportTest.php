<?php

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Filament\Resources\BulkImports\Pages\CreateBulkImport;
use App\Filament\Resources\BulkImports\Pages\ListBulkImports;
use App\Infrastructure\Messaging\Outbox\Models\OutboxMessage;
use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Protocol\Messages\ParseRequested;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('bulk-imports.disk'));

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

    Storage::disk(config('bulk-imports.disk'))->assertExists($import->source_object_key);

    $outbox = OutboxMessage::query()->sole();
    $envelope = MessageEnvelope::fromArray($outbox->payload);

    expect($outbox->routing_key)->toBe('consent.parse.requested')
        ->and($outbox->published_at)->toBeNull()
        ->and($envelope->messageId)->toBe($outbox->getKey())
        ->and($envelope->type())->toBe($outbox->routing_key)
        ->and($envelope->correlationId)->toBe($import->getKey())
        ->and($envelope->message)->toBeInstanceOf(ParseRequested::class)
        ->and($envelope->message->bulkImportId)->toBe($import->getKey())
        ->and($envelope->message->sourceObjectKey)->toBe($import->source_object_key);
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
