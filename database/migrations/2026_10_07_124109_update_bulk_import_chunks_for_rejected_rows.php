<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chunk no longer gets its own result file: the final result is built from the chunk
     * files, leaving out the rows listed in the chunk's error file.
     */
    public function up(): void
    {
        Schema::table('bulk_import_chunks', function (Blueprint $table) {
            $table->dropColumn('result_object_key');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_import_chunks', function (Blueprint $table) {
            $table->text('result_object_key')->nullable()->after('source_object_key');
        });
    }
};
