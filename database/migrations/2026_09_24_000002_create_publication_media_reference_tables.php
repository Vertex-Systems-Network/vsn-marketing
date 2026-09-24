<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('publication_media_references')) {
            Schema::create('publication_media_references', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('publication_attempt_id');
                $table->uuid('snapshot_id');
                $table->uuid('asset_id');
                $table->uuid('asset_original_id');
                $table->uuid('asset_variant_id')->nullable();
                $table->string('asset_reference_kind', 32);
                $table->uuid('canonical_asset_reference_id');
                $table->char('asset_content_sha256', 64);
                $table->uuid('provider_connection_id');
                $table->uuid('capability_evidence_id');
                $table->uuid('provider_id');
                $table->string('provider_reference_kind', 32);
                $table->string('provider_reference', 512);
                $table->string('state', 32);
                $table->unsignedBigInteger('state_version');
                $table->timestampTz('expires_at')->nullable();
                $table->char('idempotency_key', 64);
                $table->char('reference_hash', 64);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['publication_attempt_id', 'workspace_id'],
                    'publication_media_attempt_workspace_fk',
                )->references(['id', 'workspace_id'])->on('publication_attempts')->restrictOnDelete();
                $table->foreign(
                    ['snapshot_id', 'workspace_id'],
                    'publication_media_snapshot_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(
                    ['asset_id', 'workspace_id'],
                    'publication_media_asset_workspace_fk',
                )->references(['id', 'workspace_id'])->on('assets')->restrictOnDelete();
                $table->foreign(
                    ['asset_original_id', 'workspace_id'],
                    'publication_media_original_workspace_fk',
                )->references(['id', 'workspace_id'])->on('asset_originals')->restrictOnDelete();
                $table->foreign(
                    ['asset_variant_id', 'workspace_id'],
                    'publication_media_variant_workspace_fk',
                )->references(['id', 'workspace_id'])->on('asset_variants')->restrictOnDelete();
                $table->foreign(
                    ['provider_connection_id', 'workspace_id'],
                    'publication_media_connection_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
                $table->foreign(
                    ['capability_evidence_id', 'workspace_id'],
                    'publication_media_capability_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_capabilities')->restrictOnDelete();
                $table->foreign(
                    ['provider_id', 'workspace_id'],
                    'publication_media_provider_workspace_fk',
                )->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'publication_media_id_workspace_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'publication_media_idempotency_uq');
                $table->unique(
                    ['workspace_id', 'provider_connection_id', 'provider_reference_kind', 'provider_reference'],
                    'publication_media_provider_reference_uq',
                );
                $table->index(
                    ['workspace_id', 'publication_attempt_id', 'state'],
                    'publication_media_attempt_state_idx',
                );
                $table->index(
                    ['workspace_id', 'canonical_asset_reference_id'],
                    'publication_media_asset_reference_idx',
                );
            });
        }

        $this->createStateGuard();
    }

    public function down(): void
    {
        $this->dropStateGuard();
        Schema::dropIfExists('publication_media_references');
    }

    private function createStateGuard(): void
    {
        if (! Schema::hasTable('publication_media_references')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_publication_media_reference_state_machine() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'publication media reference history cannot be deleted';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
        OR NEW.publication_attempt_id IS DISTINCT FROM OLD.publication_attempt_id
        OR NEW.snapshot_id IS DISTINCT FROM OLD.snapshot_id
        OR NEW.asset_id IS DISTINCT FROM OLD.asset_id
        OR NEW.asset_original_id IS DISTINCT FROM OLD.asset_original_id
        OR NEW.asset_variant_id IS DISTINCT FROM OLD.asset_variant_id
        OR NEW.asset_reference_kind IS DISTINCT FROM OLD.asset_reference_kind
        OR NEW.canonical_asset_reference_id IS DISTINCT FROM OLD.canonical_asset_reference_id
        OR NEW.asset_content_sha256 IS DISTINCT FROM OLD.asset_content_sha256
        OR NEW.provider_connection_id IS DISTINCT FROM OLD.provider_connection_id
        OR NEW.capability_evidence_id IS DISTINCT FROM OLD.capability_evidence_id
        OR NEW.provider_id IS DISTINCT FROM OLD.provider_id
        OR NEW.provider_reference_kind IS DISTINCT FROM OLD.provider_reference_kind
        OR NEW.provider_reference IS DISTINCT FROM OLD.provider_reference
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.reference_hash IS DISTINCT FROM OLD.reference_hash
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'publication media reference derivative authority is immutable';
    END IF;

    IF NEW.state_version <> OLD.state_version + 1 OR NEW.updated_at <= OLD.updated_at THEN
        RAISE EXCEPTION 'publication media reference state version is not monotonic';
    END IF;

    IF NOT (
        (OLD.state = 'pending' AND NEW.state IN ('processing', 'ready', 'failed', 'expired'))
        OR (OLD.state = 'processing' AND NEW.state IN ('ready', 'failed', 'expired'))
        OR (OLD.state = 'ready' AND NEW.state = 'expired')
    ) THEN
        RAISE EXCEPTION 'publication media reference state transition is invalid';
    END IF;

    IF OLD.expires_at IS NOT NULL AND NEW.updated_at >= OLD.expires_at AND NEW.state <> 'expired' THEN
        RAISE EXCEPTION 'expired publication media reference must be expired';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS publication_media_references_state_machine ON publication_media_references;');
            DB::unprepared('CREATE TRIGGER publication_media_references_state_machine BEFORE UPDATE OR DELETE ON publication_media_references FOR EACH ROW EXECUTE FUNCTION enforce_publication_media_reference_state_machine();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_media_references_immutable_authority BEFORE UPDATE ON publication_media_references WHEN NEW.id IS NOT OLD.id OR NEW.workspace_id IS NOT OLD.workspace_id OR NEW.publication_attempt_id IS NOT OLD.publication_attempt_id OR NEW.snapshot_id IS NOT OLD.snapshot_id OR NEW.asset_id IS NOT OLD.asset_id OR NEW.asset_original_id IS NOT OLD.asset_original_id OR NEW.asset_variant_id IS NOT OLD.asset_variant_id OR NEW.asset_reference_kind IS NOT OLD.asset_reference_kind OR NEW.canonical_asset_reference_id IS NOT OLD.canonical_asset_reference_id OR NEW.asset_content_sha256 IS NOT OLD.asset_content_sha256 OR NEW.provider_connection_id IS NOT OLD.provider_connection_id OR NEW.capability_evidence_id IS NOT OLD.capability_evidence_id OR NEW.provider_id IS NOT OLD.provider_id OR NEW.provider_reference_kind IS NOT OLD.provider_reference_kind OR NEW.provider_reference IS NOT OLD.provider_reference OR NEW.expires_at IS NOT OLD.expires_at OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.reference_hash IS NOT OLD.reference_hash OR NEW.created_at IS NOT OLD.created_at BEGIN SELECT RAISE(ABORT, 'publication media reference derivative authority is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_media_references_monotonic BEFORE UPDATE ON publication_media_references WHEN NEW.state_version <> OLD.state_version + 1 OR NEW.updated_at <= OLD.updated_at OR NOT ((OLD.state = 'pending' AND NEW.state IN ('processing', 'ready', 'failed', 'expired')) OR (OLD.state = 'processing' AND NEW.state IN ('ready', 'failed', 'expired')) OR (OLD.state = 'ready' AND NEW.state = 'expired')) OR (OLD.expires_at IS NOT NULL AND NEW.updated_at >= OLD.expires_at AND NEW.state <> 'expired') BEGIN SELECT RAISE(ABORT, 'publication media reference state transition is invalid'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_media_references_no_delete BEFORE DELETE ON publication_media_references BEGIN SELECT RAISE(ABORT, 'publication media reference history cannot be deleted'); END;");
        }
    }

    private function dropStateGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_media_references_state_machine ON publication_media_references;');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_publication_media_reference_state_machine();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_media_references_immutable_authority;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_media_references_monotonic;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_media_references_no_delete;');
        }
    }
};
