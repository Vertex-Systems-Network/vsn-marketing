<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('primary_email')->index();
            $table->string('primary_phone')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('status')->default('active')->index(); // active, subscribed, unsubscribed, bounced, complained
            $table->timestampTz('email_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            // Enforce workspace-scoped uniqueness on primary email
            $table->unique(['workspace_id', 'primary_email'], 'contacts_workspace_email_uq');
            
            // Index for workspace isolation queries
            $table->index(['workspace_id', 'status'], 'contacts_workspace_status_idx');
        });

        Schema::create('contact_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('provider_key')->index(); // e.g., 'mailchimp', 'sendgrid', 'hubspot'
            $table->string('external_id')->index(); // External provider's ID
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            // Ensure one identity per provider per contact
            $table->unique(['contact_id', 'provider_key'], 'contact_identities_contact_provider_uq');
            
            // Index for finding contacts by external provider ID
            $table->index(['provider_key', 'external_id'], 'contact_identities_provider_external_idx');
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            // Enforce workspace-scoped uniqueness on domain
            $table->unique(['workspace_id', 'domain'], 'companies_workspace_domain_uq');
            
            // Index for workspace queries
            $table->index(['workspace_id', 'name'], 'companies_workspace_name_idx');
        });

        Schema::create('company_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role')->nullable(); // e.g., 'decision_maker', 'influencer', 'end_user'
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            // Prevent duplicate company-contact relationships
            $table->unique(['company_id', 'contact_id'], 'company_contacts_company_contact_uq');
            
            // Index for finding contacts by company
            $table->index('company_id', 'company_contacts_company_idx');
            
            // Index for finding companies by contact
            $table->index('contact_id', 'company_contacts_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('contact_identities');
        Schema::dropIfExists('contacts');
    }
};
