<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->unique(['id', 'workspace_id'], 'brands_id_workspace_uq');
        });
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->unique(
                ['id', 'contact_id', 'workspace_id'],
                'contact_identity_id_contact_workspace_uq',
            );
        });

        Schema::create('event_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('canonical_name', 191);
            $table->unsignedSmallInteger('schema_version');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'event_type_id_workspace_uq');
            $table->unique(
                ['workspace_id', 'canonical_name', 'schema_version'],
                'event_type_workspace_name_schema_uq',
            );
        });

        Schema::create('customer_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('event_type_id');
            $table->uuid('contact_id');
            $table->uuid('contact_identity_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->string('source', 120);
            $table->string('source_event_id', 191)->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->json('subjects');
            $table->json('payload');
            $table->json('source_metadata');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(['brand_id', 'workspace_id'], 'customer_event_brand_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('brands')
                ->restrictOnDelete();
            $table->foreign(['event_type_id', 'workspace_id'], 'customer_event_type_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('event_types')
                ->restrictOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'customer_event_contact_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contacts')
                ->restrictOnDelete();
            $table->foreign(
                ['contact_identity_id', 'contact_id', 'workspace_id'],
                'customer_event_identity_contact_workspace_fk',
            )
                ->references(['id', 'contact_id', 'workspace_id'])
                ->on('contact_identities')
                ->restrictOnDelete();
            $table->index(
                ['workspace_id', 'contact_id', 'occurred_at', 'received_at'],
                'customer_event_contact_timeline_idx',
            );
            $table->index(['workspace_id', 'source', 'source_event_id'], 'customer_event_source_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
        Schema::dropIfExists('event_types');

        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->dropUnique('contact_identity_id_contact_workspace_uq');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_id_workspace_uq');
        });
    }
};
