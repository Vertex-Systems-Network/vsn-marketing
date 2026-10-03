<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('event_id')->unique();
            $table->string('scope_key', 64);
            $table->string('subject_key', 64);
            $table->string('source_key', 64)->unique();
            $table->string('event_type', 120);
            $table->string('source', 120);
            $table->string('envelope_hash', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('event_id')->references('id')->on('customer_events')->restrictOnDelete();
            $table->foreign(['brand_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('brands')->restrictOnDelete();
            $table->index(['workspace_id', 'scope_key', 'occurred_at', 'received_at'], 'analytics_fact_window_idx');
            $table->index(['workspace_id', 'subject_key'], 'analytics_fact_subject_idx');
        });
        Schema::create('analytics_conflicts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->uuid('workspace_id');
            $table->string('scope_key', 64);
            $table->string('source_key', 64);
            $table->string('candidate_hash', 64);
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
        Schema::create('analytics_invalidations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->uuid('workspace_id');
            $table->string('scope_key', 64);
            $table->string('subject_key', 64);
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
        Schema::create('analytics_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('scope_key', 64);
            $table->string('fingerprint', 64);
            $table->json('report');
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Rollback destroys derived analytics evidence: back up/restore before production rollback.
        Schema::dropIfExists('analytics_snapshots');
        Schema::dropIfExists('analytics_invalidations');
        Schema::dropIfExists('analytics_conflicts');
        Schema::dropIfExists('analytics_facts');
    }
};
