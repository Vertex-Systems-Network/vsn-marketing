<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('lifecycle', 32);
            $table->string('created_by_actor_id', 191);
            $table->json('audit_provenance');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'content_document_id_workspace_uq');
            $table->index(['workspace_id', 'lifecycle'], 'content_document_workspace_lifecycle_idx');
        });

        Schema::create('content_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('document_id');
            $table->uuid('parent_version_id')->nullable();
            $table->unsignedBigInteger('version_number');
            $table->unsignedInteger('schema_version');
            $table->string('status', 32);
            $table->json('canonical_tree');
            $table->json('audit_provenance');
            $table->string('idempotency_key', 191);
            $table->string('created_by_actor_id', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['document_id', 'workspace_id'], 'content_version_document_workspace_fk')
                ->references(['id', 'workspace_id'])->on('content_documents')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'content_version_id_workspace_uq');
            $table->foreign(['parent_version_id', 'workspace_id'], 'content_version_parent_workspace_fk')
                ->references(['id', 'workspace_id'])->on('content_versions')->restrictOnDelete();
            $table->unique(['document_id', 'version_number'], 'content_version_document_number_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'content_version_workspace_idempotency_uq');
            $table->index(['workspace_id', 'document_id', 'status'], 'content_version_workspace_document_status_idx');
        });

        Schema::create('content_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('lifecycle', 32);
            $table->string('created_by_actor_id', 191);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'content_template_id_workspace_uq');
            $table->index(['workspace_id', 'lifecycle'], 'content_template_workspace_lifecycle_idx');
        });

        Schema::create('content_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('template_id');
            $table->uuid('parent_version_id')->nullable();
            $table->unsignedBigInteger('version_number');
            $table->unsignedInteger('schema_version');
            $table->string('status', 32);
            $table->json('definition');
            $table->json('variable_schema');
            $table->json('localization_schema');
            $table->json('dependencies');
            $table->string('idempotency_key', 191);
            $table->string('created_by_actor_id', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['template_id', 'workspace_id'], 'content_template_version_owner_workspace_fk')
                ->references(['id', 'workspace_id'])->on('content_templates')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'content_template_version_id_workspace_uq');
            $table->foreign(['parent_version_id', 'workspace_id'], 'content_template_version_parent_workspace_fk')
                ->references(['id', 'workspace_id'])->on('content_template_versions')->restrictOnDelete();
            $table->unique(['template_id', 'version_number'], 'content_template_version_owner_number_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'content_template_version_workspace_idempotency_uq');
            $table->index(['workspace_id', 'template_id', 'status'], 'content_template_version_owner_status_idx');
        });

        Schema::create('reusable_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('lifecycle', 32);
            $table->string('created_by_actor_id', 191);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'reusable_component_id_workspace_uq');
            $table->index(['workspace_id', 'lifecycle'], 'reusable_component_workspace_lifecycle_idx');
        });

        Schema::create('reusable_component_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('component_id');
            $table->uuid('parent_version_id')->nullable();
            $table->unsignedBigInteger('version_number');
            $table->unsignedInteger('schema_version');
            $table->string('status', 32);
            $table->json('definition');
            $table->json('variable_schema');
            $table->json('localization_schema');
            $table->json('dependencies');
            $table->string('idempotency_key', 191);
            $table->string('created_by_actor_id', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['component_id', 'workspace_id'], 'reusable_component_version_owner_workspace_fk')
                ->references(['id', 'workspace_id'])->on('reusable_components')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'reusable_component_version_id_workspace_uq');
            $table->foreign(['parent_version_id', 'workspace_id'], 'reusable_component_version_parent_workspace_fk')
                ->references(['id', 'workspace_id'])->on('reusable_component_versions')->restrictOnDelete();
            $table->unique(['component_id', 'version_number'], 'reusable_component_version_owner_number_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'reusable_component_version_workspace_idempotency_uq');
            $table->index(['workspace_id', 'component_id', 'status'], 'reusable_component_version_owner_status_idx');
        });

        $this->createVersionImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropVersionImmutabilityGuards();

        Schema::dropIfExists('reusable_component_versions');
        Schema::dropIfExists('reusable_components');
        Schema::dropIfExists('content_template_versions');
        Schema::dropIfExists('content_templates');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('content_documents');
    }

    private function createVersionImmutabilityGuards(): void
    {
        $tables = [
            'content_versions',
            'content_template_versions',
            'reusable_component_versions',
        ];

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_canonical_content_version_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'canonical content/template/component versions are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            foreach ($tables as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_canonical_content_version_mutation();");
            }
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'canonical content/template/component versions are immutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'canonical content/template/component versions are immutable'); END;");
            }
        }
    }

    private function dropVersionImmutabilityGuards(): void
    {
        $tables = [
            'content_versions',
            'content_template_versions',
            'reusable_component_versions',
        ];

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            foreach ($tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS reject_canonical_content_version_mutation();');
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update;");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete;");
            }
        }
    }
};
