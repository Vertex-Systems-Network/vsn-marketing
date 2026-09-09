<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('operation_id');
            $table->uuid('provider_id');
            $table->uuid('provider_connection_id');
            $table->unsignedInteger('attempt_number');
            $table->string('operation_class', 191);
            $table->string('outcome_class', 48);
            $table->string('recovery_action', 48);
            $table->boolean('retry_allowed')->default(false);
            $table->string('failure_reason', 191);
            $table->string('error_category', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('minimum_delay_seconds')->nullable();
            $table->timestampTz('reset_at')->nullable();
            $table->boolean('provider_accepted')->default(false);
            $table->boolean('acceptance_known_not_occurred')->default(false);
            $table->boolean('request_may_have_reached_provider')->default(false);
            $table->unsignedInteger('max_attempts');
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['operation_id', 'workspace_id'], 'delivery_attempt_operation_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_operations')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'delivery_attempt_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();
            $table->foreign(
                ['provider_connection_id', 'provider_id', 'workspace_id'],
                'delivery_attempt_connection_provider_workspace_fk',
            )->references(['id', 'provider_id', 'workspace_id'])
                ->on('provider_connections')
                ->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'delivery_attempt_id_workspace_uq');
            $table->unique(
                ['workspace_id', 'operation_id', 'attempt_number'],
                'delivery_attempt_operation_number_uq',
            );
            $table->index(
                ['workspace_id', 'provider_connection_id', 'operation_class', 'created_at'],
                'delivery_attempt_connection_class_idx',
            );
        });

        Schema::create('delivery_reconciliations', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('operation_id');
            $table->uuid('attempt_id');
            $table->string('resolution', 48);
            $table->boolean('retry_safe')->default(false);
            $table->unsignedInteger('probe_attempt_number')->default(0);
            $table->unsignedInteger('max_probe_attempts');
            $table->boolean('operator_action_required')->default(false);
            $table->string('reason', 191);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['operation_id', 'workspace_id'], 'delivery_recon_operation_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_operations')->cascadeOnDelete();
            $table->foreign(['attempt_id', 'workspace_id'], 'delivery_recon_attempt_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_attempts')->cascadeOnDelete();
            $table->primary('attempt_id', 'delivery_reconciliation_attempt_pk');
            $table->index(
                ['workspace_id', 'resolution', 'updated_at'],
                'delivery_reconciliation_workspace_state_idx',
            );
        });

        Schema::create('delivery_dead_letters', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('operation_id');
            $table->uuid('attempt_id');
            $table->string('reason', 48);
            $table->string('audit_reason', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['operation_id', 'workspace_id'], 'delivery_dlq_operation_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_operations')->cascadeOnDelete();
            $table->foreign(['attempt_id', 'workspace_id'], 'delivery_dlq_attempt_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_attempts')->cascadeOnDelete();
            $table->primary('attempt_id', 'delivery_dead_letter_attempt_pk');
            $table->index(['workspace_id', 'reason', 'created_at'], 'delivery_dead_letter_workspace_reason_idx');
        });

        Schema::create('delivery_circuit_breakers', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->uuid('workspace_id');
            $table->uuid('provider_id');
            $table->uuid('provider_connection_id');
            $table->string('operation_class', 191);
            $table->string('state', 32);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('next_probe_at')->nullable();
            $table->boolean('probe_in_flight')->default(false);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'delivery_breaker_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(
                ['provider_connection_id', 'provider_id', 'workspace_id'],
                'delivery_breaker_connection_provider_workspace_fk',
            )->references(['id', 'provider_id', 'workspace_id'])
                ->on('provider_connections')
                ->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'provider_connection_id', 'operation_class'],
                'delivery_breaker_connection_class_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_circuit_breakers');
        Schema::dropIfExists('delivery_dead_letters');
        Schema::dropIfExists('delivery_reconciliations');
        Schema::dropIfExists('delivery_attempts');
    }
};
