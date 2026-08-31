<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider_key', 120);
            $table->string('display_name', 191);
            $table->string('category', 64)->nullable();
            $table->json('metadata');
            $table->text('source_url');
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'provider_key'], 'providers_workspace_key_uq');
            $table->unique(['id', 'workspace_id'], 'providers_id_workspace_uq');
        });

        Schema::create('provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('provider_id');
            $table->string('name', 191);
            $table->string('readiness_status', 48);
            $table->string('auth_family', 48);
            $table->string('secret_reference', 512);
            $table->json('requested_scopes');
            $table->json('granted_scopes');
            $table->json('roles');
            $table->string('access_tier', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('principal_type', 64)->nullable();
            $table->string('principal_reference', 191)->nullable();
            $table->string('provider_review_status', 120)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->boolean('refresh_supported')->default(false);
            $table->timestampTz('last_rotated_at')->nullable();
            $table->json('metadata');
            $table->text('source_url');
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'provider_conn_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'provider_conn_id_workspace_uq');
            $table->unique(['workspace_id', 'provider_id', 'name'], 'provider_conn_workspace_name_uq');
        });

        Schema::create('provider_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('provider_id');
            $table->uuid('connection_id')->nullable();
            $table->string('operation', 191);
            $table->string('support_status', 32);
            $table->json('required_scopes');
            $table->json('required_roles');
            $table->json('constraints');
            $table->text('source_url');
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'provider_cap_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(['connection_id', 'workspace_id'], 'provider_cap_conn_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'provider_cap_id_workspace_uq');
            $table->index(['workspace_id', 'provider_id', 'operation'], 'provider_cap_lookup_idx');
        });

        Schema::create('provider_quotas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('provider_id');
            $table->uuid('connection_id')->nullable();
            $table->string('operation', 191);
            $table->string('scope_type', 64);
            $table->string('scope_reference', 191)->nullable();
            $table->string('unit', 64);
            $table->string('window_type', 64);
            $table->unsignedInteger('window_seconds')->nullable();
            $table->string('region', 120)->nullable();
            $table->string('principal_type', 64)->nullable();
            $table->string('principal_reference', 191)->nullable();
            $table->string('account_tier', 120)->nullable();
            $table->decimal('limit_value', 24, 6)->nullable();
            $table->decimal('used_value', 24, 6)->nullable();
            $table->decimal('remaining_value', 24, 6)->nullable();
            $table->timestampTz('resets_at')->nullable();
            $table->boolean('dynamically_discovered')->default(false);
            $table->string('discovery_key', 191)->nullable();
            $table->json('metadata');
            $table->text('source_url');
            $table->string('source_version', 120)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['provider_id', 'workspace_id'], 'provider_quota_provider_workspace_fk')
                ->references(['id', 'workspace_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(['connection_id', 'workspace_id'], 'provider_quota_conn_workspace_fk')
                ->references(['id', 'workspace_id'])->on('provider_connections')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'provider_quota_id_workspace_uq');
            $table->index(['workspace_id', 'provider_id', 'operation'], 'provider_quota_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_quotas');
        Schema::dropIfExists('provider_capabilities');
        Schema::dropIfExists('provider_connections');
        Schema::dropIfExists('providers');
    }
};
