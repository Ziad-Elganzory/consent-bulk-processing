<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            // UUID v7: also the published message_id, and its order is the publishing order.
            $table->uuid('id')->primary();
            $table->string('exchange');
            $table->string('routing_key');
            $table->json('payload');
            // Failed publish attempts; the relay keeps retrying, these explain why it is stuck.
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
