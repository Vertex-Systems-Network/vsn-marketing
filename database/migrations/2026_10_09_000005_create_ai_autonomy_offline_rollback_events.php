<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only operator evidence. No provider sends, refunds or
        // automatic rollback authority is materialized in this table.
        Schema::create('ai_autonomy_offline_rollback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('actor_id', 64);
            $table->string('run_id', 64);
            $table->char('snapshot_sha256', 64);
            $table->char('review_fingerprint', 64);
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('reviewed_at_unix');
            $table->string('status', 48);
            $table->string('reason_code', 128);
            $table->boolean('external_outcome_verified');
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique(['workspace_id', 'run_id', 'sequence'], 'ai_rollback_event_sequence_unique');
            $table->unique(['workspace_id', 'run_id', 'review_fingerprint'], 'ai_rollback_review_fingerprint_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_offline_rollback_events')
            && DB::table('ai_autonomy_offline_rollback_events')->exists()) {
            throw new RuntimeException('Cannot delete immutable rollback evidence without reviewed restore plan.');
        }

        Schema::dropIfExists('ai_autonomy_offline_rollback_events');
    }
};
