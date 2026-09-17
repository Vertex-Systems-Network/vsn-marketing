<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppression_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->string('channel', 64);
            $table->string('purpose', 120);
            $table->string('authority_type', 48);
            $table->string('source_type', 64);
            $table->string('source_version', 120)->nullable();
            $table->string('provider_key', 120)->nullable();
            $table->string('idempotency_key', 191);
            $table->string('evidence_fingerprint', 128);
            $table->timestampTz('observed_at');
            $table->timestampTz('effective_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->json('immutable_evidence');
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'suppression_contact_workspace_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'suppression_records_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'suppression_records_workspace_idem_uq');
            $table->index(
                ['workspace_id', 'contact_id', 'channel', 'purpose', 'effective_at'],
                'suppression_effective_lookup_idx'
            );
        });

        Schema::create('preference_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->string('channel', 64);
            $table->string('purpose', 120);
            $table->string('scope_type', 64);
            $table->string('scope_key', 191)->nullable();
            $table->string('decision', 32);
            $table->string('basis_type', 64)->nullable();
            $table->string('source_type', 64);
            $table->string('source_version', 120)->nullable();
            $table->string('idempotency_key', 191);
            $table->string('evidence_fingerprint', 128);
            $table->timestampTz('observed_at');
            $table->timestampTz('effective_at');
            $table->json('immutable_evidence');
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'preference_contact_workspace_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'preference_records_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'preference_records_workspace_idem_uq');
            $table->index(
                ['workspace_id', 'contact_id', 'channel', 'purpose', 'scope_type', 'scope_key', 'effective_at'],
                'preference_effective_lookup_idx'
            );
        });

        Schema::create('unsubscribe_token_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->string('channel', 64);
            $table->string('purpose', 120);
            $table->string('scope_type', 64);
            $table->string('scope_key', 191)->nullable();
            $table->string('token_digest', 64);
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->nullable();
            $table->json('metadata');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'unsubscribe_token_contact_workspace_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'unsubscribe_token_scopes_id_workspace_uq');
            $table->unique(['workspace_id', 'token_digest'], 'unsubscribe_token_workspace_digest_uq');
            $table->index(
                ['workspace_id', 'contact_id', 'channel', 'purpose'],
                'unsubscribe_token_subject_lookup_idx'
            );
        });

        Schema::create('suppression_reconciliation_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('suppression_record_id');
            $table->uuid('provider_connection_id')->nullable();
            $table->string('provider_key', 120)->nullable();
            $table->string('operation_state', 48);
            $table->string('outcome_class', 48)->nullable();
            $table->string('idempotency_key', 191);
            $table->string('request_fingerprint', 128);
            $table->string('provider_operation_reference', 191)->nullable();
            $table->boolean('ambiguous_outcome')->default(false);
            $table->timestampTz('started_at');
            $table->timestampTz('timeout_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('audit_evidence');
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['suppression_record_id', 'workspace_id'], 'suppression_reconcile_record_workspace_fk')
                ->references(['id', 'workspace_id'])->on('suppression_records')->cascadeOnDelete();
            $table->foreign(['provider_connection_id', 'workspace_id'], 'suppression_reconcile_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'suppression_reconcile_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'suppression_reconcile_workspace_idem_uq');
            $table->index(
                ['workspace_id', 'suppression_record_id', 'started_at'],
                'suppression_reconcile_record_lookup_idx'
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_task0027_evidence_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'TASK-0027 evidence records are append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            foreach (['suppression_records', 'preference_records', 'unsubscribe_token_scopes'] as $table) {
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s_append_only BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION reject_task0027_evidence_mutation()',
                    $table,
                    $table,
                ));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_reconciliation_operations');
        Schema::dropIfExists('unsubscribe_token_scopes');
        Schema::dropIfExists('preference_records');
        Schema::dropIfExists('suppression_records');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_task0027_evidence_mutation()');
        }
    }
};
