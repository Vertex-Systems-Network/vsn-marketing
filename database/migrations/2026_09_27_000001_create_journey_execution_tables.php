<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journeys')) Schema::create('journeys', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('workspace_id'); $t->string('name', 191); $t->string('status', 20)->default('draft'); $t->timestampsTz();
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete(); $t->index(['workspace_id','status']);
        });
        if (! Schema::hasTable('journey_versions')) Schema::create('journey_versions', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('workspace_id'); $t->uuid('journey_id'); $t->unsignedInteger('version_number'); $t->json('graph'); $t->char('definition_hash',64); $t->string('status',20)->default('draft'); $t->timestampsTz();
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete(); $t->foreign('journey_id')->references('id')->on('journeys')->cascadeOnDelete(); $t->unique(['journey_id','version_number']); $t->index(['workspace_id','definition_hash']);
        });
        if (! Schema::hasTable('journey_executions')) Schema::create('journey_executions', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('workspace_id'); $t->uuid('journey_version_id'); $t->uuid('subject_id'); $t->uuid('enrollment_id'); $t->char('execution_key',64); $t->string('status',20)->default('queued'); $t->unsignedInteger('revision')->default(0); $t->json('transition_history')->nullable(); $t->timestampsTz();
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete(); $t->foreign('journey_version_id')->references('id')->on('journey_versions')->cascadeOnDelete(); $t->unique(['workspace_id','execution_key']); $t->index(['workspace_id','status']);
        });
        if (! Schema::hasTable('journey_node_attempts')) Schema::create('journey_node_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('workspace_id'); $t->uuid('execution_id'); $t->string('node_id',128); $t->unsignedInteger('attempt'); $t->char('attempt_key',64); $t->string('status',20)->default('queued'); $t->timestampTz('lease_until')->nullable(); $t->json('error')->nullable(); $t->timestampsTz();
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete(); $t->foreign('execution_id')->references('id')->on('journey_executions')->cascadeOnDelete(); $t->unique(['workspace_id','attempt_key']); $t->index(['workspace_id','status','lease_until']);
        });
    }
    public function down(): void { Schema::dropIfExists('journey_node_attempts'); Schema::dropIfExists('journey_executions'); Schema::dropIfExists('journey_versions'); Schema::dropIfExists('journeys'); }
};
