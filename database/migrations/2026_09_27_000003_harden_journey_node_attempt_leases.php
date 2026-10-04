<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journey_node_attempts')) {
            return;
        }
        Schema::table('journey_node_attempts', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable();
            $table->unsignedInteger('lease_generation')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('error_class', 32)->nullable();
            $table->index(['workspace_id', 'status', 'available_at'], 'journey_attempt_available_idx');
        });
        if (! Schema::hasTable('journey_execution_transitions')) {
            Schema::create('journey_execution_transitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('execution_id');
                $table->unsignedInteger('transition_revision');
                $table->string('event_type', 32);
                $table->string('node_id', 128)->nullable();
                $table->unsignedInteger('attempt')->nullable();
                $table->json('metadata')->nullable();
                $table->timestampTz('created_at');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['workspace_id', 'execution_id'])->references(['workspace_id', 'id'])->on('journey_executions')->cascadeOnDelete();
                $table->unique(['workspace_id', 'execution_id', 'transition_revision'], 'journey_transition_revision_uq');
                $table->index(['workspace_id', 'execution_id', 'created_at'], 'journey_transition_timeline_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('journey_node_attempts')) {
            return;
        }
        if ((Schema::hasTable('journey_execution_transitions') && DB::table('journey_execution_transitions')->exists())
            || DB::table('journey_node_attempts')->where('lease_generation', '>', 0)->orWhereNotNull('completed_at')->exists()) {
            throw new RuntimeException('Cannot drop active journey lease state while node-attempt evidence exists.');
        }
        Schema::dropIfExists('journey_execution_transitions');
        Schema::table('journey_node_attempts', function (Blueprint $table): void {
            $table->dropIndex('journey_attempt_available_idx');
            $table->dropColumn(['lease_token', 'lease_generation', 'available_at', 'completed_at', 'error_class']);
        });
    }
};
