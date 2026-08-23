<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 191);
            $table->timestampsTz();

            $table->unique(['id', 'workspace_id'], 'contact_lists_id_workspace_uq');
            $table->unique(['workspace_id', 'name'], 'contact_lists_workspace_name_uq');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 191);
            $table->timestampsTz();

            $table->unique(['id', 'workspace_id'], 'tags_id_workspace_uq');
            $table->unique(['workspace_id', 'name'], 'tags_workspace_name_uq');
        });

        Schema::create('contact_list_memberships', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('list_id');
            $table->uuid('contact_id');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['list_id', 'workspace_id'], 'contact_list_membership_list_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contact_lists')
                ->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'contact_list_membership_contact_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contacts')
                ->cascadeOnDelete();
            $table->primary(
                ['workspace_id', 'list_id', 'contact_id'],
                'contact_list_membership_pk',
            );
            $table->index(['workspace_id', 'contact_id'], 'contact_list_membership_contact_idx');
        });

        Schema::create('contact_tag_assignments', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('tag_id');
            $table->uuid('contact_id');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['tag_id', 'workspace_id'], 'contact_tag_assignment_tag_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('tags')
                ->cascadeOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'contact_tag_assignment_contact_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contacts')
                ->cascadeOnDelete();
            $table->primary(
                ['workspace_id', 'tag_id', 'contact_id'],
                'contact_tag_assignment_pk',
            );
            $table->index(['workspace_id', 'contact_id'], 'contact_tag_assignment_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag_assignments');
        Schema::dropIfExists('contact_list_memberships');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contact_lists');
    }
};
