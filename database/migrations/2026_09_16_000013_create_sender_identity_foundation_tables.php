<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('canonical_domain', 253);
            $table->string('lifecycle_state', 48);
            $table->string('idempotency_key', 191);
            $table->json('metadata');
            $table->timestampsTz();

            $table->unique(['id', 'workspace_id'], 'sender_domains_id_workspace_uq');
            $table->unique(['workspace_id', 'canonical_domain'], 'sender_domains_workspace_domain_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'sender_domains_workspace_idem_uq');
        });

        Schema::create('sender_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('sender_domain_id');
            $table->uuid('provider_connection_id')->nullable();
            $table->string('local_part', 64);
            $table->string('email_address', 320);
            $table->string('display_name', 191)->nullable();
            $table->string('reply_to_address', 320)->nullable();
            $table->string('lifecycle_state', 48);
            $table->string('purpose', 64);
            $table->string('provider_key', 120)->nullable();
            $table->json('policy_context');
            $table->string('idempotency_key', 191);
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['sender_domain_id', 'workspace_id'], 'sender_identity_domain_workspace_fk')
                ->references(['id', 'workspace_id'])->on('sender_domains')->cascadeOnDelete();
            $table->foreign(['provider_connection_id', 'workspace_id'], 'sender_identity_provider_conn_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->nullOnDelete();
            $table->unique(['id', 'workspace_id'], 'sender_identities_id_workspace_uq');
            $table->unique(['workspace_id', 'email_address'], 'sender_identities_workspace_email_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'sender_identities_workspace_idem_uq');
            $table->index(['workspace_id', 'sender_domain_id'], 'sender_identities_domain_lookup_idx');
        });

        Schema::create('sender_authentication_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('sender_domain_id');
            $table->string('dimension', 48);
            $table->string('evidence_status', 32);
            $table->string('evidence_version', 120);
            $table->string('source_type', 64);
            $table->string('provider_key', 120)->nullable();
            $table->json('public_material');
            $table->json('redacted_evidence');
            $table->text('source_url')->nullable();
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->timestampTz('recorded_at');
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['sender_domain_id', 'workspace_id'], 'sender_auth_domain_workspace_fk')
                ->references(['id', 'workspace_id'])->on('sender_domains')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'sender_auth_evidence_id_workspace_uq');
            $table->unique(
                ['workspace_id', 'sender_domain_id', 'dimension', 'evidence_version'],
                'sender_auth_evidence_version_uq'
            );
            $table->index(
                ['workspace_id', 'sender_domain_id', 'dimension', 'observed_at'],
                'sender_auth_evidence_latest_idx'
            );
        });

        Schema::create('mailbox_provider_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider_key', 120);
            $table->string('policy_key', 120);
            $table->string('policy_version', 120);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->decimal('high_volume_threshold', 24, 6)->nullable();
            $table->string('threshold_unit', 64)->nullable();
            $table->json('classification_inputs');
            $table->json('requirements');
            $table->text('provenance_url');
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->json('metadata');
            $table->timestampsTz();

            $table->unique(['id', 'workspace_id'], 'mailbox_policies_id_workspace_uq');
            $table->unique(
                ['workspace_id', 'provider_key', 'policy_key', 'policy_version'],
                'mailbox_policies_version_uq'
            );
            $table->index(
                ['workspace_id', 'provider_key', 'effective_from'],
                'mailbox_policies_effective_lookup_idx'
            );
        });

        Schema::create('sender_verification_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('sender_domain_id');
            $table->uuid('sender_identity_id')->nullable();
            $table->string('provider_key', 120)->nullable();
            $table->string('operation_type', 64);
            $table->string('operation_state', 48);
            $table->string('outcome_class', 48)->nullable();
            $table->string('idempotency_key', 191);
            $table->string('request_fingerprint', 128);
            $table->string('provider_operation_reference', 191)->nullable();
            $table->string('mutation_mode', 32)->default('read_only');
            $table->boolean('production_activation_permitted')->default(false);
            $table->boolean('ambiguous_outcome')->default(false);
            $table->timestampTz('started_at');
            $table->timestampTz('timeout_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('audit_evidence');
            $table->json('metadata');
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['sender_domain_id', 'workspace_id'], 'sender_verify_domain_workspace_fk')
                ->references(['id', 'workspace_id'])->on('sender_domains')->cascadeOnDelete();
            $table->foreign(['sender_identity_id', 'workspace_id'], 'sender_verify_identity_workspace_fk')
                ->references(['id', 'workspace_id'])->on('sender_identities')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'sender_verify_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'sender_verify_workspace_idem_uq');
            $table->index(
                ['workspace_id', 'sender_domain_id', 'operation_type', 'started_at'],
                'sender_verify_domain_operation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_verification_operations');
        Schema::dropIfExists('mailbox_provider_policies');
        Schema::dropIfExists('sender_authentication_evidence');
        Schema::dropIfExists('sender_identities');
        Schema::dropIfExists('sender_domains');
    }
};
