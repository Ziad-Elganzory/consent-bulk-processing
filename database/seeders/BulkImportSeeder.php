<?php

namespace Database\Seeders;

use App\Domains\BulkImport\Models\BulkImport;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BulkImportSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        BulkImport::factory()->create();
    }
}
