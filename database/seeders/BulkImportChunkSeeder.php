<?php

namespace Database\Seeders;

use App\Domains\BulkImport\Models\BulkImportChunk;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BulkImportChunkSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        BulkImportChunk::factory()->create();
    }
}
