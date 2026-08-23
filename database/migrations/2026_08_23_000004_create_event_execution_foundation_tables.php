<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->timestampTz('dead_lettered_at')->nullable()->index();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_id')->nullable()->index();
            $table->string('actor_id', 191)->nullable()->index();
            $table->string('action', 191)->index();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->json('evidence');
            $table->string('correlation_id', 191)->nullable()->index();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(['workspace_id', 'occurred_at'], 'audit_workspace_occurred_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->string('scope', 120);
            $table->string('idempotency_key', 191);
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'scope', 'idempotency_key'], 'idempotency_workspace_scope_key_uq');
            $table->index(['status', 'updated_at'], 'idempotency_status_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_events');

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('dead_lettered_at');
        });
    }
};
