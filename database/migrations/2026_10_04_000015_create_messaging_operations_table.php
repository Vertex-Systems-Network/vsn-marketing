<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messaging_operations')) {
            $columns = ['id', 'workspace_id', 'channel', 'provider_key', 'idempotency_key', 'request_fingerprint', 'operation_state', 'provider_operation_id', 'ambiguous_outcome', 'evidence', 'created_at', 'updated_at'];
            $indexes = Schema::getIndexes('messaging_operations');
            $primary = array_filter($indexes, fn (array $i): bool => $i['primary'] && $i['columns'] === ['id']);
            $unique = array_filter($indexes, fn (array $i): bool => $i['unique'] && $i['columns'] === ['workspace_id', 'idempotency_key']);
            $foreign = array_filter(Schema::getForeignKeys('messaging_operations'), fn (array $f): bool => $f['columns'] === ['workspace_id'] && $f['foreign_table'] === 'workspaces' && $f['foreign_columns'] === ['id']);
            if (! Schema::hasColumns('messaging_operations', $columns) || $primary === [] || $unique === [] || $foreign === []) {
                throw new RuntimeException('Unknown messaging operation schema; preserve evidence and restore the reviewed schema before retry.');
            }

            return;
        }
        Schema::create('messaging_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('channel', 64);
            $table->string('provider_key', 120);
            $table->string('idempotency_key', 191);
            $table->string('request_fingerprint', 128);
            $table->string('operation_state', 48);
            $table->string('provider_operation_id', 191)->nullable();
            $table->boolean('ambiguous_outcome')->default(false);
            $table->json('evidence');
            $table->timestampsTz();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'idempotency_key'], 'messaging_operations_workspace_idem_uq');
            $table->index(['workspace_id', 'provider_key', 'channel'], 'messaging_operations_provider_lookup_idx');
            $table->index(['workspace_id', 'operation_state'], 'messaging_operations_state_lookup_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('messaging_operations') && DB::table('messaging_operations')->exists()) {
            throw new RuntimeException('Non-empty messaging operation evidence must be backed up and restored before rollback.');
        } Schema::dropIfExists('messaging_operations');
    }
};
