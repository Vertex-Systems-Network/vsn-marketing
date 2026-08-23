<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('name');
            $table->string('domain', 253)->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'brand_id'], 'companies_workspace_brand_idx');
            $table->unique(['id', 'workspace_id'], 'companies_id_workspace_uq');
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->uuid('company_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->timestampsTz();

            $table->foreign(['company_id', 'workspace_id'], 'contacts_company_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('companies')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id'], 'contacts_workspace_brand_idx');
            $table->index(['workspace_id', 'company_id'], 'contacts_workspace_company_idx');
            $table->unique(['id', 'workspace_id'], 'contacts_id_workspace_uq');
        });

        Schema::create('contact_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->string('type', 32);
            $table->string('value', 320);
            $table->string('normalized_value', 320);
            $table->string('provider', 120)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'contact_identity_contact_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contacts')
                ->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'type', 'normalized_value'],
                'contact_identity_workspace_type_value_uq',
            );
            $table->unique(
                ['workspace_id', 'provider', 'provider_reference'],
                'contact_identity_provider_ref_uq',
            );
            $table->index(['contact_id', 'type'], 'contact_identity_contact_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identities');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
