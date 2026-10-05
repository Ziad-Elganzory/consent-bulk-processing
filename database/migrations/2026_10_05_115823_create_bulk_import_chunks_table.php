<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bulk_import_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bulk_import_id')->constrained('bulk_imports')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('valid_rows')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->text('source_object_key')->nullable();
            $table->text('result_object_key')->nullable();
            $table->text('error_object_key')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['bulk_import_id', 'sequence']);
            $table->index(['bulk_import_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_import_chunks');
    }
};
