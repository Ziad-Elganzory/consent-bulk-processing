<?php

namespace Database\Factories\Domains\BulkImport\Models;

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Domains\BulkImport\Models\BulkImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BulkImport>
 */
class BulkImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => BulkImportStatus::AwaitingUpload,
            'original_filename' => null,
            'source_object_key' => null,
            'source_size_bytes' => null,
            'output_object_key' => null,
            'error_object_key' => null,
            'failure_message' => null,
            'completed_at' => null,
        ];
    }
}
