<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_recipient_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['id', 'workspace_id', 'message_snapshot_id'],
                'delivery_recipient_id_workspace_message_uq',
            );
        });

        Schema::create('delivery_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('message_snapshot_id');
            $table->uuid('recipient_snapshot_id');
            $table->uuid('provider_id')->nullable();
            $table->uuid('provider_connection_id')->nullable();
            $table->string('channel', 32);
            $table->char('idempotency_key', 64);
            $table->timestampTz('scheduled_not_before_at');
            $table->string('priority_class', 32);
            $table->string('state', 32);
            $table->string('queue_name', 120);
            $table->char('queue_partition_key', 64);
            $table->string('backpressure_reason', 64)->nullable();
            $table->timestampTz('backpressured_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['message_snapshot_id', 'workspace_id'], 'delivery_operation_message_snapshot_fk')
                ->references(['id', 'workspace_id'])->on('delivery_message_snapshots')->restrictOnDelete();
            $table->foreign(
                ['recipient_snapshot_id', 'workspace_id', 'message_snapshot_id'],
                'delivery_operation_recipient_snapshot_fk',
            )->references(['id', 'workspace_id', 'message_snapshot_id'])
                ->on('delivery_recipient_snapshots')
                ->restrictOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'delivery_operation_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();
            $table->foreign(['provider_connection_id', 'workspace_id'], 'delivery_operation_connection_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'delivery_operation_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'delivery_operation_workspace_idempotency_uq');
            $table->index(
                ['workspace_id', 'state', 'scheduled_not_before_at'],
                'delivery_operation_workspace_state_schedule_idx',
            );
            $table->index(
                ['queue_name', 'state', 'scheduled_not_before_at'],
                'delivery_operation_queue_state_schedule_idx',
            );
            $table->index(
                ['workspace_id', 'queue_partition_key', 'state'],
                'delivery_operation_partition_state_idx',
            );
            $table->index(
                ['workspace_id', 'provider_connection_id', 'state'],
                'delivery_operation_connection_state_idx',
            );
        });

        Schema::create('delivery_operation_quota_consumptions', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('operation_id');
            $table->uuid('provider_id');
            $table->uuid('provider_connection_id');
            $table->uuid('quota_id');
            $table->decimal('units', 24, 6);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['operation_id', 'workspace_id'], 'delivery_quota_operation_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_operations')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'delivery_quota_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();
            $table->foreign(['provider_connection_id', 'workspace_id'], 'delivery_quota_connection_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
            $table->foreign(['quota_id', 'workspace_id'], 'delivery_quota_evidence_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_quotas')->restrictOnDelete();
            $table->unique(['operation_id', 'quota_id'], 'delivery_quota_operation_evidence_uq');
            $table->index(
                ['workspace_id', 'provider_connection_id', 'quota_id'],
                'delivery_quota_connection_evidence_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_operation_quota_consumptions');
        Schema::dropIfExists('delivery_operations');

        Schema::table('delivery_recipient_snapshots', function (Blueprint $table): void {
            $table->dropUnique('delivery_recipient_id_workspace_message_uq');
        });
    }
};
