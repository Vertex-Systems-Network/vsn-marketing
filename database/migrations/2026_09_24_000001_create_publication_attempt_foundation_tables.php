<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('publication_attempts')) {
            Schema::create('publication_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('execution_intent_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->uuid('target_id');
                $table->char('target_hash', 64);
                $table->string('channel', 32);
                $table->uuid('provider_connection_id');
                $table->uuid('capability_evidence_id');
                $table->uuid('provider_id');
                $table->char('idempotency_key', 64);
                $table->string('state', 32);
                $table->unsignedBigInteger('state_version');
                $table->char('attempt_hash', 64);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['execution_intent_id', 'workspace_id'],
                    'publication_attempt_intent_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedule_execution_intents')->restrictOnDelete();
                $table->foreign(
                    ['campaign_id', 'workspace_id'],
                    'publication_attempt_campaign_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(
                    ['snapshot_id', 'workspace_id'],
                    'publication_attempt_snapshot_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(
                    ['target_id', 'workspace_id'],
                    'publication_attempt_target_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_targets')->restrictOnDelete();
                $table->foreign(
                    ['provider_connection_id', 'workspace_id'],
                    'publication_attempt_connection_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
                $table->foreign(
                    ['capability_evidence_id', 'workspace_id'],
                    'publication_attempt_capability_workspace_fk',
                )->references(['id', 'workspace_id'])->on('provider_capabilities')->restrictOnDelete();
                $table->foreign(
                    ['provider_id', 'workspace_id'],
                    'publication_attempt_provider_workspace_fk',
                )->references(['id', 'workspace_id'])->on('providers')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'publication_attempt_id_workspace_uq');
                $table->unique(
                    ['workspace_id', 'execution_intent_id', 'target_id'],
                    'publication_attempt_intent_target_uq',
                );
                $table->unique(['workspace_id', 'idempotency_key'], 'publication_attempt_idempotency_uq');
                $table->index(['workspace_id', 'state', 'updated_at'], 'publication_attempt_state_idx');
            });
        }

        $this->createStateGuard();
    }

    public function down(): void
    {
        $this->dropStateGuard();
        Schema::dropIfExists('publication_attempts');
    }

    private function createStateGuard(): void
    {
        if (! Schema::hasTable('publication_attempts')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_publication_attempt_state_machine() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'publication attempt history cannot be deleted';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
        OR NEW.execution_intent_id IS DISTINCT FROM OLD.execution_intent_id
        OR NEW.campaign_id IS DISTINCT FROM OLD.campaign_id
        OR NEW.snapshot_id IS DISTINCT FROM OLD.snapshot_id
        OR NEW.target_id IS DISTINCT FROM OLD.target_id
        OR NEW.target_hash IS DISTINCT FROM OLD.target_hash
        OR NEW.channel IS DISTINCT FROM OLD.channel
        OR NEW.provider_connection_id IS DISTINCT FROM OLD.provider_connection_id
        OR NEW.capability_evidence_id IS DISTINCT FROM OLD.capability_evidence_id
        OR NEW.provider_id IS DISTINCT FROM OLD.provider_id
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.attempt_hash IS DISTINCT FROM OLD.attempt_hash
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'publication attempt authority evidence is immutable';
    END IF;

    IF NEW.state_version <> OLD.state_version + 1 OR NEW.updated_at <= OLD.updated_at THEN
        RAISE EXCEPTION 'publication attempt state version is not monotonic';
    END IF;

    IF NOT (
        (OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'failed_terminal', 'cancelled'))
        OR (OLD.state = 'dispatching' AND NEW.state IN ('published', 'failed_retriable', 'failed_terminal'))
        OR (OLD.state = 'failed_retriable' AND NEW.state IN ('dispatching', 'failed_terminal', 'cancelled'))
    ) THEN
        RAISE EXCEPTION 'publication attempt state transition is invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS publication_attempts_state_machine ON publication_attempts;');
            DB::unprepared('CREATE TRIGGER publication_attempts_state_machine BEFORE UPDATE OR DELETE ON publication_attempts FOR EACH ROW EXECUTE FUNCTION enforce_publication_attempt_state_machine();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_attempts_immutable_authority BEFORE UPDATE ON publication_attempts WHEN NEW.id IS NOT OLD.id OR NEW.workspace_id IS NOT OLD.workspace_id OR NEW.execution_intent_id IS NOT OLD.execution_intent_id OR NEW.campaign_id IS NOT OLD.campaign_id OR NEW.snapshot_id IS NOT OLD.snapshot_id OR NEW.target_id IS NOT OLD.target_id OR NEW.target_hash IS NOT OLD.target_hash OR NEW.channel IS NOT OLD.channel OR NEW.provider_connection_id IS NOT OLD.provider_connection_id OR NEW.capability_evidence_id IS NOT OLD.capability_evidence_id OR NEW.provider_id IS NOT OLD.provider_id OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.attempt_hash IS NOT OLD.attempt_hash OR NEW.created_at IS NOT OLD.created_at BEGIN SELECT RAISE(ABORT, 'publication attempt authority evidence is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_attempts_monotonic BEFORE UPDATE ON publication_attempts WHEN NEW.state_version <> OLD.state_version + 1 OR NEW.updated_at <= OLD.updated_at OR NOT ((OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'failed_terminal', 'cancelled')) OR (OLD.state = 'dispatching' AND NEW.state IN ('published', 'failed_retriable', 'failed_terminal')) OR (OLD.state = 'failed_retriable' AND NEW.state IN ('dispatching', 'failed_terminal', 'cancelled'))) BEGIN SELECT RAISE(ABORT, 'publication attempt state transition is invalid'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS publication_attempts_no_delete BEFORE DELETE ON publication_attempts BEGIN SELECT RAISE(ABORT, 'publication attempt history cannot be deleted'); END;");
        }
    }

    private function dropStateGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_attempts_state_machine ON publication_attempts;');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_publication_attempt_state_machine();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_attempts_immutable_authority;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_attempts_monotonic;');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_attempts_no_delete;');
        }
    }
};
