<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journey_work_items')) {
            return;
        }

        Schema::create('journey_work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('execution_id');
            $table->string('node_id', 128);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('available_at');
            $table->timestampTz('enqueued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('queue_age_us')->nullable();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'execution_id'])->references(['workspace_id', 'id'])->on('journey_executions')->cascadeOnDelete();
            $table->unique(['workspace_id', 'execution_id', 'node_id'], 'journey_work_node_uq');
            $table->index(['status', 'available_at', 'id'], 'journey_work_due_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journey_work_items')) {
            return;
        }
        if (DB::table('journey_work_items')->exists()) {
            throw new RuntimeException('Journey work rollback refused while durable work items exist.');
        }

        Schema::drop('journey_work_items');
    }
};
