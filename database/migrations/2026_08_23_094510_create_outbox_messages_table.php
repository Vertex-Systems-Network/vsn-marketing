<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->string('topic', 160);
            $table->string('aggregate_type', 160)->nullable(); $table->string('aggregate_id', 191)->nullable();
            $table->string('idempotency_key', 191)->unique(); $table->json('payload'); $table->json('headers');
            $table->timestampTz('occurred_at'); $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable(); $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('relay_attempts')->default(0); $table->text('last_error')->nullable(); $table->timestampsTz();
            $table->index(['published_at', 'available_at', 'locked_at'], 'outbox_relay_scan_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('outbox_messages'); }
};
