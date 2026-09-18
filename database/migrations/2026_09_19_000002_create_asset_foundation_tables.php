<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('kind', 32);
            $table->string('lifecycle', 32);
            $table->string('created_by_actor_id', 191);
            $table->json('audit_provenance');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'asset_id_workspace_uq');
            $table->index(['workspace_id', 'kind', 'lifecycle'], 'asset_workspace_kind_lifecycle_idx');
        });

        Schema::create('asset_originals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('asset_id');
            $table->uuid('parent_original_id')->nullable();
            $table->unsignedBigInteger('version_number');
            $table->unsignedInteger('schema_version');
            $table->char('content_sha256', 64);
            $table->string('observed_media_type', 191);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('storage_disk', 64);
            $table->string('storage_key', 1024);
            $table->json('source_metadata');
            $table->json('rights_metadata');
            $table->string('created_by_actor_id', 191);
            $table->json('audit_provenance');
            $table->string('idempotency_key', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['asset_id', 'workspace_id'], 'asset_original_asset_workspace_fk')
                ->references(['id', 'workspace_id'])->on('assets')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'asset_original_id_workspace_uq');
            $table->foreign(['parent_original_id', 'workspace_id'], 'asset_original_parent_workspace_fk')
                ->references(['id', 'workspace_id'])->on('asset_originals')->restrictOnDelete();
            $table->unique(['asset_id', 'version_number'], 'asset_original_asset_version_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'asset_original_workspace_idempotency_uq');
            $table->index(['workspace_id', 'content_sha256'], 'asset_original_workspace_hash_idx');
        });

        Schema::create('asset_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('source_original_id');
            $table->unsignedInteger('schema_version');
            $table->json('transformation_spec');
            $table->char('transformation_hash', 64);
            $table->string('processor_id', 191);
            $table->string('processor_version', 191);
            $table->char('output_sha256', 64);
            $table->string('observed_media_type', 191);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('storage_disk', 64);
            $table->string('storage_key', 1024);
            $table->json('audit_provenance');
            $table->string('idempotency_key', 191);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['source_original_id', 'workspace_id'], 'asset_variant_source_workspace_fk')
                ->references(['id', 'workspace_id'])->on('asset_originals')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'asset_variant_id_workspace_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'asset_variant_workspace_idempotency_uq');
            $table->unique(
                ['workspace_id', 'source_original_id', 'transformation_hash', 'processor_id', 'processor_version'],
                'asset_variant_deterministic_request_uq',
            );
            $table->index(['workspace_id', 'source_original_id'], 'asset_variant_workspace_source_idx');
        });

        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();

        Schema::dropIfExists('asset_variants');
        Schema::dropIfExists('asset_originals');
        Schema::dropIfExists('assets');
    }

    private function createImmutabilityGuards(): void
    {
        $tables = ['asset_originals', 'asset_variants'];
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_canonical_asset_artifact_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'canonical asset originals and variants are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);

            foreach ($tables as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_canonical_asset_artifact_mutation();");
            }
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'canonical asset originals and variants are immutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'canonical asset originals and variants are immutable'); END;");
            }
        }
    }

    private function dropImmutabilityGuards(): void
    {
        $tables = ['asset_originals', 'asset_variants'];
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach ($tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS reject_canonical_asset_artifact_mutation();');
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update;");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete;");
            }
        }
    }
};
