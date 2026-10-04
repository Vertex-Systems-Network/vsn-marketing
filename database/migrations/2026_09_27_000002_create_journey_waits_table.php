<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journey_waits')) {
            return;
        }

        Schema::create('journey_waits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('execution_id');
            $table->string('node_id', 128);
            $table->char('wait_key', 64);
            $table->timestampTz('wake_at');
            $table->json('predicate')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('resumed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'execution_id'])
                ->references(['workspace_id', 'id'])
                ->on('journey_executions')
                ->cascadeOnDelete();
            $table->unique(['workspace_id', 'wait_key'], 'journey_wait_workspace_key_uq');
            $table->index(['workspace_id', 'status', 'wake_at'], 'journey_wait_due_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journey_waits')) {
            return;
        }

        if (DB::table('journey_waits')->exists()) {
            throw new RuntimeException('Journey wait rollback refused: persisted wait state must be preserved or explicitly migrated first.');
        }

        Schema::drop('journey_waits');
    }
};
