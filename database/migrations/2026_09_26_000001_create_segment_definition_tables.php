<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('status', 16)->default('draft');
            $table->uuid('created_by_actor_id');
            $table->timestampsTz();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'segment_definition_id_workspace_uq');
            $table->index(['workspace_id', 'status'], 'segment_definition_workspace_status_idx');
        });

        Schema::create('segment_definition_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('definition_id');
            $table->unsignedInteger('version_number');
            $table->json('definition_ast');
            $table->char('definition_hash', 64);
            $table->uuid('created_by_actor_id');
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['definition_id', 'workspace_id'], 'segment_version_definition_workspace_fk')
                ->references(['id', 'workspace_id'])->on('segment_definitions')->cascadeOnDelete();
            $table->unique(['definition_id', 'version_number'], 'segment_version_definition_number_uq');
            $table->unique(['id', 'workspace_id'], 'segment_version_id_workspace_uq');
            $table->index(['workspace_id', 'definition_hash'], 'segment_version_workspace_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_definition_versions');
        Schema::dropIfExists('segment_definitions');
    }
};
