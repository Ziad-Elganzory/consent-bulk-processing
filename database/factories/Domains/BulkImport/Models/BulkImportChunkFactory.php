<?php

namespace Database\Factories\Domains\BulkImport\Models;

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Domains\BulkImport\Models\BulkImportChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BulkImportChunk>
 */
class BulkImportChunkFactory extends Factory
{
    protected $model = BulkImportChunk::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bulk_import_id' => BulkImport::factory()->state([
                'status' => BulkImportStatus::Validating,
            ]),
            'sequence' => 1,
            'status' => BulkImportChunkStatus::Pending,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'source_object_key' => 'consent/import-example/chunks/chunk-000001.csv',
            'error_object_key' => null,
            'failure_message' => null,
        ];
    }
}
