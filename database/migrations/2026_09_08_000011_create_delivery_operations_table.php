<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_message_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['id', 'workspace_id', 'channel'],
                'delivery_snapshot_id_workspace_channel_uq',
            );
        });

        Schema::table('delivery_recipient_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['id', 'message_snapshot_id', 'workspace_id', 'channel'],
                'delivery_recipient_execution_binding_uq',
            );
        });

        Schema::create('delivery_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('message_snapshot_id');
            $table->uuid('recipient_snapshot_id');
            $table->uuid('provider_connection_id');
            $table->string('channel', 32);
            $table->char('idempotency_key', 64);
            $table->timestampTz('scheduled_not_before_at');
            $table->string('priority_class', 32);
            $table->string('state', 32);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('backpressure_reason', 120)->nullable();
            $table->timestampTz('backpressured_at')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(
                ['message_snapshot_id', 'workspace_id', 'channel'],
                'delivery_operation_message_snapshot_fk',
            )->references(['id', 'workspace_id', 'channel'])
                ->on('delivery_message_snapshots')
                ->restrictOnDelete();
            $table->foreign(
                ['recipient_snapshot_id', 'message_snapshot_id', 'workspace_id', 'channel'],
                'delivery_operation_recipient_snapshot_fk',
            )->references(['id', 'message_snapshot_id', 'workspace_id', 'channel'])
                ->on('delivery_recipient_snapshots')
                ->restrictOnDelete();
            $table->foreign(
                ['provider_connection_id', 'workspace_id'],
                'delivery_operation_provider_connection_fk',
            )->references(['id', 'workspace_id'])
                ->on('provider_connections')
                ->restrictOnDelete();

            $table->unique(['workspace_id', 'idempotency_key'], 'delivery_operation_idempotency_uq');
            $table->unique(['id', 'workspace_id'], 'delivery_operation_id_workspace_uq');
            $table->index(
                ['workspace_id', 'provider_connection_id', 'channel', 'state', 'scheduled_not_before_at'],
                'delivery_operation_admission_lookup_idx',
            );
            $table->index(
                ['workspace_id', 'state', 'priority_class', 'scheduled_not_before_at', 'created_at'],
                'delivery_operation_fair_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_operations');

        Schema::table('delivery_recipient_snapshots', function (Blueprint $table): void {
            $table->dropUnique('delivery_recipient_execution_binding_uq');
        });

        Schema::table('delivery_message_snapshots', function (Blueprint $table): void {
            $table->dropUnique('delivery_snapshot_id_workspace_channel_uq');
        });
    }
};
