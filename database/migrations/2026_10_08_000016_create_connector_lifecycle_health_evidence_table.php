<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_lifecycle_health')) {
            $columns = [
                'id', 'workspace_id', 'provider_key', 'status', 'reason', 'observed_at',
                'compatibility_evidence_sha256', 'deprecation_evidence_sha256',
                'decision_audit_sha256', 'failure_code', 'reconciliation_key',
                'compatibility_evidence', 'deprecation_evidence', 'decision_evidence', 'evidence_sha256', 'created_at', 'updated_at',
            ];
            $indexes = Schema::getIndexes('connector_lifecycle_health');
            $primary = array_filter($indexes, fn (array $index): bool => $index['primary'] && $index['columns'] === ['id']);
            $unique = array_filter($indexes, fn (array $index): bool => $index['unique']
                && $index['columns'] === ['workspace_id', 'provider_key', 'reconciliation_key']);
            $foreign = Schema::getForeignKeys('connector_lifecycle_health');
            $workspaceForeign = array_filter($foreign, fn (array $key): bool => $key['columns'] === ['workspace_id']
                && $key['foreign_table'] === 'workspaces' && $key['foreign_columns'] === ['id']);
            $providerForeign = array_filter($foreign, fn (array $key): bool => $key['columns'] === ['workspace_id', 'provider_key']
                && $key['foreign_table'] === 'providers' && $key['foreign_columns'] === ['workspace_id', 'provider_key']);

            if (! Schema::hasColumns('connector_lifecycle_health', $columns)
                || $primary === [] || $unique === [] || $workspaceForeign === [] || $providerForeign === []) {
                throw new RuntimeException('Unknown connector lifecycle schema; preserve evidence and restore the reviewed schema before retry.');
            }

            return;
        }

        Schema::create('connector_lifecycle_health', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('provider_key', 120);
            $table->string('status', 32);
            $table->string('reason', 191);
            $table->timestampTz('observed_at');
            $table->char('compatibility_evidence_sha256', 64);
            $table->char('deprecation_evidence_sha256', 64)->nullable();
            $table->char('decision_audit_sha256', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->char('reconciliation_key', 64);
            $table->json('compatibility_evidence');
            $table->json('deprecation_evidence')->nullable();
            $table->json('decision_evidence')->nullable();
            $table->char('evidence_sha256', 64);
            $table->timestampsTz();

            $table->foreign('workspace_id', 'connector_lifecycle_workspace_fk')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'provider_key'], 'connector_lifecycle_provider_scope_fk')
                ->references(['workspace_id', 'provider_key'])->on('providers')->restrictOnDelete();
            $table->unique(
                ['workspace_id', 'provider_key', 'reconciliation_key'],
                'connector_lifecycle_workspace_provider_key_uq',
            );
            $table->index(
                ['workspace_id', 'provider_key', 'observed_at'],
                'connector_lifecycle_latest_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('connector_lifecycle_health')
            && DB::table('connector_lifecycle_health')->exists()) {
            throw new RuntimeException('Non-empty connector lifecycle evidence must be backed up and restored before rollback.');
        }

        Schema::dropIfExists('connector_lifecycle_health');
    }
};
