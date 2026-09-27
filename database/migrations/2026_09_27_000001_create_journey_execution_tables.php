<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journeys')) {
            Schema::create('journeys', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->string('name', 191);
                $table->string('status', 20)->default('draft');
                $table->timestampsTz();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->unique(['workspace_id', 'id']);
                $table->index(['workspace_id', 'status']);
            });
        }
        if (! Schema::hasTable('journey_versions')) {
            Schema::create('journey_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('journey_id');
                $table->unsignedInteger('version_number');
                $table->json('graph');
                $table->char('definition_hash', 64);
                $table->string('status', 20)->default('draft');
                $table->timestampsTz();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['workspace_id', 'journey_id'])->references(['workspace_id', 'id'])->on('journeys')->cascadeOnDelete();
                $table->unique(['workspace_id', 'id']);
                $table->unique(['journey_id', 'version_number']);
                $table->index(['workspace_id', 'definition_hash']);
            });
        }
        if (! Schema::hasTable('journey_executions')) {
            Schema::create('journey_executions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('journey_version_id');
                $table->uuid('subject_id');
                $table->uuid('enrollment_id');
                $table->char('execution_key', 64);
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('revision')->default(0);
                $table->json('transition_history')->nullable();
                $table->timestampsTz();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['workspace_id', 'journey_version_id'])->references(['workspace_id', 'id'])->on('journey_versions')->cascadeOnDelete();
                $table->unique(['workspace_id', 'id']);
                $table->unique(['workspace_id', 'execution_key']);
                $table->index(['workspace_id', 'status']);
            });
        }
        if (! Schema::hasTable('journey_enrollments')) {
            Schema::create('journey_enrollments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('journey_version_id');
                $table->uuid('subject_id');
                $table->string('trigger_event_id', 191);
                $table->char('enrollment_key', 64);
                $table->string('reentry_policy', 20);
                $table->unsignedInteger('generation');
                $table->string('status', 20)->default('active');
                $table->timestampsTz();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['workspace_id', 'journey_version_id'])->references(['workspace_id', 'id'])->on('journey_versions')->cascadeOnDelete();
                $table->unique(['workspace_id', 'enrollment_key']);
                $table->unique(['workspace_id', 'journey_version_id', 'subject_id', 'trigger_event_id']);
                $table->unique(['workspace_id', 'journey_version_id', 'subject_id', 'generation']);
                $table->index(['workspace_id', 'journey_version_id', 'subject_id', 'status']);
            });
        }
        if (! Schema::hasTable('journey_node_attempts')) {
            Schema::create('journey_node_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('execution_id');
                $table->string('node_id', 128);
                $table->unsignedInteger('attempt');
                $table->char('attempt_key', 64);
                $table->string('status', 20)->default('queued');
                $table->timestampTz('lease_until')->nullable();
                $table->json('error')->nullable();
                $table->timestampsTz();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['workspace_id', 'execution_id'])->references(['workspace_id', 'id'])->on('journey_executions')->cascadeOnDelete();
                $table->unique(['workspace_id', 'attempt_key']);
                $table->index(['workspace_id', 'status', 'lease_until']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_node_attempts');
        Schema::dropIfExists('journey_enrollments');
        Schema::dropIfExists('journey_executions');
        Schema::dropIfExists('journey_versions');
        Schema::dropIfExists('journeys');
    }
};
