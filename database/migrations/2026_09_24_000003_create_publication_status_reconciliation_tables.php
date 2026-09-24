<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('publication_status_observations')) {
            Schema::create('publication_status_observations', function (Blueprint $table): void {
                $table->bigIncrements('sequence');
                $table->uuid('id');
                $table->uuid('workspace_id');
                $table->uuid('publication_attempt_id');
                $table->uuid('provider_connection_id');
                $table->uuid('capability_evidence_id');
                $table->uuid('provider_id');
                $table->string('provider_operation_id', 512);
                $table->string('normalized_status', 32);
                $table->string('provider_status', 120);
                $table->string('reconciliation_source', 32);
                $table->string('source_reference', 191);
                $table->timestampTz('provider_observed_at');
                $table->timestampTz('received_at');
                $table->json('evidence');
                $table->char('idempotency_key', 64);
                $table->char('observation_hash', 64);

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['publication_attempt_id', 'workspace_id'],
                    'publication_status_observation_attempt_workspace_fk',
                )->references(['id', 'workspace_id'])->on('publication_attempts')->restrictOnDelete();
                $table->foreign(
                    ['provider_connection_id', 'workspace_id'],
                    'publication_status_observation_connection_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
                $table->foreign(
                    ['capability_evidence_id', 'workspace_id'],
                    'publication_status_observation_capability_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_capabilities')->restrictOnDelete();
                $table->foreign(
                    ['provider_id', 'workspace_id'],
                    'publication_status_observation_provider_workspace_fk',
                )->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'publication_status_observation_id_workspace_uq');
                $table->unique(
                    ['id', 'workspace_id', 'publication_attempt_id'],
                    'publication_status_observation_attempt_identity_uq',
                );
                $table->unique(['workspace_id', 'idempotency_key'], 'publication_status_observation_idempotency_uq');
                $table->index(
                    ['workspace_id', 'publication_attempt_id', 'sequence'],
                    'publication_status_observation_attempt_sequence_idx',
                );
                $table->index(
                    ['workspace_id', 'provider_connection_id', 'provider_operation_id', 'provider_observed_at'],
                    'publication_status_observation_provider_operation_idx',
                );
            });
        }

        if (! Schema::hasTable('publication_status_projections')) {
            Schema::create('publication_status_projections', function (Blueprint $table): void {
                $table->uuid('publication_attempt_id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('provider_connection_id');
                $table->uuid('capability_evidence_id');
                $table->uuid('provider_id');
                $table->string('provider_operation_id', 512);
                $table->string('normalized_status', 32);
                $table->string('provider_status', 120);
                $table->timestampTz('provider_observed_at');
                $table->string('reconciliation_source', 32);
                $table->string('source_reference', 191);
                $table->uuid('current_observation_id');
                $table->char('current_observation_hash', 64);
                $table->unsignedBigInteger('projection_version');
                $table->timestampTz('updated_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['publication_attempt_id', 'workspace_id'],
                    'publication_status_projection_attempt_workspace_fk',
                )->references(['id', 'workspace_id'])->on('publication_attempts')->restrictOnDelete();
                $table->foreign(
                    ['provider_connection_id', 'workspace_id'],
                    'publication_status_projection_connection_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
                $table->foreign(
                    ['capability_evidence_id', 'workspace_id'],
                    'publication_status_projection_capability_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_capabilities')->restrictOnDelete();
                $table->foreign(
                    ['provider_id', 'workspace_id'],
                    'publication_status_projection_provider_workspace_fk',
                )->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();
                $table->foreign(
                    ['current_observation_id', 'workspace_id', 'publication_attempt_id'],
                    'publication_status_projection_observation_attempt_fk',
                )->references(['id', 'workspace_id', 'publication_attempt_id'])->on('publication_status_observations')->restrictOnDelete();

                $table->unique(
                    ['publication_attempt_id', 'workspace_id'],
                    'publication_status_projection_attempt_workspace_uq',
                );
                $table->unique(
                    ['workspace_id', 'provider_connection_id', 'provider_operation_id'],
                    'publication_status_projection_provider_operation_uq',
                );
                $table->index(
                    ['workspace_id', 'normalized_status', 'provider_observed_at'],
                    'publication_status_projection_status_idx',
                );
            });
        }

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('publication_status_projections');
        Schema::dropIfExists('publication_status_observations');
    }

    private function createGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_publication_status_observation_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'publication status observations are append-only';
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_observations_append_only ON publication_status_observations;');
            DB::unprepared('CREATE TRIGGER publication_status_observations_append_only BEFORE UPDATE OR DELETE ON publication_status_observations FOR EACH ROW EXECUTE FUNCTION reject_publication_status_observation_mutation();');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_publication_status_projection_monotonicity() RETURNS trigger AS $$
DECLARE
    old_rank integer;
    new_rank integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'publication status projection cannot be deleted';
    END IF;

    IF NEW.publication_attempt_id IS DISTINCT FROM OLD.publication_attempt_id
        OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
        OR NEW.provider_connection_id IS DISTINCT FROM OLD.provider_connection_id
        OR NEW.capability_evidence_id IS DISTINCT FROM OLD.capability_evidence_id
        OR NEW.provider_id IS DISTINCT FROM OLD.provider_id
        OR NEW.provider_operation_id IS DISTINCT FROM OLD.provider_operation_id
    THEN
        RAISE EXCEPTION 'publication status projection authority is immutable';
    END IF;

    IF NEW.projection_version <> OLD.projection_version + 1 OR NEW.updated_at < OLD.updated_at THEN
        RAISE EXCEPTION 'publication status projection version is not monotonic';
    END IF;

    IF NEW.provider_observed_at < OLD.provider_observed_at THEN
        RAISE EXCEPTION 'publication status projection provider timestamp cannot regress';
    END IF;

    IF OLD.normalized_status IN ('succeeded', 'failed', 'cancelled')
        AND NEW.normalized_status <> OLD.normalized_status
    THEN
        RAISE EXCEPTION 'terminal publication status projection cannot be rewritten';
    END IF;

    IF OLD.normalized_status NOT IN ('succeeded', 'failed', 'cancelled')
        AND NEW.normalized_status = 'unknown'
        AND OLD.normalized_status <> 'unknown'
    THEN
        RAISE EXCEPTION 'publication status projection cannot regress to unknown';
    END IF;

    old_rank := CASE OLD.normalized_status
        WHEN 'unknown' THEN -1
        WHEN 'accepted' THEN 0
        WHEN 'pending' THEN 1
        WHEN 'in_progress' THEN 2
        ELSE 3
    END;

    new_rank := CASE NEW.normalized_status
        WHEN 'unknown' THEN -1
        WHEN 'accepted' THEN 0
        WHEN 'pending' THEN 1
        WHEN 'in_progress' THEN 2
        ELSE 3
    END;

    IF OLD.normalized_status NOT IN ('succeeded', 'failed', 'cancelled')
        AND NEW.normalized_status NOT IN ('succeeded', 'failed', 'cancelled')
        AND new_rank < old_rank
    THEN
        RAISE EXCEPTION 'publication status projection cannot regress';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_projections_monotonic ON publication_status_projections;');
            DB::unprepared('CREATE TRIGGER publication_status_projections_monotonic BEFORE UPDATE OR DELETE ON publication_status_projections FOR EACH ROW EXECUTE FUNCTION enforce_publication_status_projection_monotonicity();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_status_observations_immutable_update BEFORE UPDATE ON publication_status_observations BEGIN SELECT RAISE(ABORT, 'publication status observations are append-only'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_status_observations_immutable_delete BEFORE DELETE ON publication_status_observations BEGIN SELECT RAISE(ABORT, 'publication status observations are append-only'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_status_projections_no_delete BEFORE DELETE ON publication_status_projections BEGIN SELECT RAISE(ABORT, 'publication status projection cannot be deleted'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_status_projections_authority BEFORE UPDATE ON publication_status_projections WHEN NEW.publication_attempt_id IS NOT OLD.publication_attempt_id OR NEW.workspace_id IS NOT OLD.workspace_id OR NEW.provider_connection_id IS NOT OLD.provider_connection_id OR NEW.capability_evidence_id IS NOT OLD.capability_evidence_id OR NEW.provider_id IS NOT OLD.provider_id OR NEW.provider_operation_id IS NOT OLD.provider_operation_id BEGIN SELECT RAISE(ABORT, 'publication status projection authority is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_status_projections_monotonic BEFORE UPDATE ON publication_status_projections WHEN NEW.projection_version <> OLD.projection_version + 1 OR NEW.updated_at < OLD.updated_at OR NEW.provider_observed_at < OLD.provider_observed_at OR (OLD.normalized_status IN ('succeeded', 'failed', 'cancelled') AND NEW.normalized_status <> OLD.normalized_status) OR (OLD.normalized_status NOT IN ('succeeded', 'failed', 'cancelled') AND NEW.normalized_status = 'unknown' AND OLD.normalized_status <> 'unknown') OR (OLD.normalized_status NOT IN ('succeeded', 'failed', 'cancelled') AND NEW.normalized_status NOT IN ('succeeded', 'failed', 'cancelled') AND (CASE NEW.normalized_status WHEN 'unknown' THEN -1 WHEN 'accepted' THEN 0 WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END) < (CASE OLD.normalized_status WHEN 'unknown' THEN -1 WHEN 'accepted' THEN 0 WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END)) BEGIN SELECT RAISE(ABORT, 'publication status projection is not monotonic'); END;");
        }
    }

    private function dropGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_observations_append_only ON publication_status_observations;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_projections_monotonic ON publication_status_projections;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_publication_status_observation_mutation();');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_publication_status_projection_monotonicity();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_observations_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_observations_immutable_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_projections_no_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_projections_authority;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_status_projections_monotonic;');
        }
    }
};
