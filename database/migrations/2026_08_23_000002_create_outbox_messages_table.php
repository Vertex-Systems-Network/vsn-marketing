<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('topic', 191);
            $table->string('aggregate_type', 120);
            $table->string('aggregate_id', 191);
            $table->json('payload');
            $table->json('headers');
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['published_at', 'available_at'], 'outbox_pending_available_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
