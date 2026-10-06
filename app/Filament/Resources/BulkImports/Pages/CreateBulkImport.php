<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Override;
use Throwable;

class CreateBulkImport extends CreateRecord
{
    protected static string $resource = BulkImportResource::class;

    #[Locked]
    public string $uploadId;

    #[Override]
    public function mount(): void
    {
        $this->uploadId = (string) Str::uuid();

        parent::mount();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        $sourceObjectKey = $data['source_object_key'] ?? null;

        if (! is_string($sourceObjectKey)) {
            throw ValidationException::withMessages([
                'source_object_key' => 'Please select a CSV file.',
            ]);
        }

        try {
            // S3 metadata lookup; this does not download the CSV contents.
            $sourceSizeBytes = Storage::disk('s3')->size($sourceObjectKey);
            $now = now();

            return DB::transaction(function () use ($data, $sourceObjectKey, $sourceSizeBytes, $now): Model {
                $record = new BulkImport([
                    ...$data,
                    'user_id' => auth()->id(),
                    'status' => BulkImportStatus::Queued,
                    'source_size_bytes' => $sourceSizeBytes,
                ]);

                // The upload directory already uses this id, so the record must share it.
                $record->setAttribute($record->getKeyName(), $this->uploadId);
                $record->save();

                DB::table('outbox_messages')->insert([
                    'id' => (string) Str::uuid(),
                    'routing_key' => 'consent.parse.requested',
                    'payload' => json_encode([
                        'bulk_import_id' => (string) $record->getKey(),
                        'source_object_key' => $sourceObjectKey,
                    ], JSON_THROW_ON_ERROR),
                    'available_at' => $now,
                    'attempt_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $record;
            });
        } catch (Throwable $exception) {
            Storage::disk('s3')->delete($sourceObjectKey);

            throw $exception;
        }
    }
}
