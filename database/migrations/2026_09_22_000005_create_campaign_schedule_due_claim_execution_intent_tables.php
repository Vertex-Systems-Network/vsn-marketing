<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_schedule_due_claims')) {
            Schema::create('campaign_schedule_due_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->uuid('schedule_id');
                $table->char('schedule_hash', 64);
                $table->uuid('scheduled_approval_id');
                $table->uuid('evaluated_approval_id');
                $table->string('state', 32);
                $table->string('lease_owner', 191);
                $table->char('lease_token_hash', 64);
                $table->timestampTz('lease_expires_at');
                $table->timestampTz('claimed_at');
                $table->unsignedInteger('attempt_number');
                $table->unsignedBigInteger('version');
                $table->timestampTz('updated_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['campaign_id', 'workspace_id'],
                    'campaign_due_claim_campaign_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(
                    ['snapshot_id', 'workspace_id'],
                    'campaign_due_claim_snapshot_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(
                    ['schedule_id', 'workspace_id'],
                    'campaign_due_claim_schedule_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();
                $table->foreign(
                    ['scheduled_approval_id', 'workspace_id'],
                    'campaign_due_claim_scheduled_approval_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();
                $table->foreign(
                    ['evaluated_approval_id', 'workspace_id'],
                    'campaign_due_claim_evaluated_approval_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'campaign_due_claim_id_workspace_uq');
                $table->unique(['workspace_id', 'schedule_id'], 'campaign_due_claim_schedule_uq');
                $table->unique(['workspace_id', 'lease_token_hash'], 'campaign_due_claim_lease_token_hash_uq');
                $table->index(
                    ['workspace_id', 'state', 'lease_expires_at'],
                    'campaign_due_claim_lease_scan_idx',
                );
            });
        }

        if (! Schema::hasTable('campaign_schedule_execution_intents')) {
            Schema::create('campaign_schedule_execution_intents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->uuid('schedule_id');
                $table->char('schedule_hash', 64);
                $table->uuid('scheduled_approval_id');
                $table->uuid('evaluated_approval_id');
                $table->uuid('claim_id');
                $table->unsignedBigInteger('claim_version');
                $table->timestampTz('resolved_at_utc');
                $table->timestampTz('claimed_at');
                $table->timestampTz('emitted_at');
                $table->uuid('outbox_id');
                $table->char('intent_hash', 64);

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['campaign_id', 'workspace_id'],
                    'campaign_execution_intent_campaign_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(
                    ['snapshot_id', 'workspace_id'],
                    'campaign_execution_intent_snapshot_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(
                    ['schedule_id', 'workspace_id'],
                    'campaign_execution_intent_schedule_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();
                $table->foreign(
                    ['scheduled_approval_id', 'workspace_id'],
                    'campaign_execution_intent_scheduled_approval_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();
                $table->foreign(
                    ['evaluated_approval_id', 'workspace_id'],
                    'campaign_execution_intent_evaluated_approval_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();
                $table->foreign(
                    ['claim_id', 'workspace_id'],
                    'campaign_execution_intent_claim_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedule_due_claims')->restrictOnDelete();
                $table->foreign('outbox_id', 'campaign_execution_intent_outbox_fk')
                    ->references('id')->on('outbox_messages')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'campaign_execution_intent_id_workspace_uq');
                $table->unique(['workspace_id', 'schedule_id'], 'campaign_execution_intent_schedule_uq');
                $table->unique(['workspace_id', 'outbox_id'], 'campaign_execution_intent_outbox_uq');
                $table->index(
                    ['workspace_id', 'campaign_id', 'emitted_at'],
                    'campaign_execution_intent_campaign_history_idx',
                );
            });
        }

        $this->createClaimCoordinationGuards();
        $this->createIntentImmutabilityGuard();
    }

    public function down(): void
    {
        $this->dropIntentImmutabilityGuard();
        $this->dropClaimCoordinationGuards();
        Schema::dropIfExists('campaign_schedule_execution_intents');
        Schema::dropIfExists('campaign_schedule_due_claims');
    }

    private function createClaimCoordinationGuards(): void
    {
        if (! Schema::hasTable('campaign_schedule_due_claims')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_campaign_schedule_due_claim_coordination() RETURNS trigger AS $claim$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'campaign schedule due claim coordination evidence cannot be deleted';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
        OR NEW.campaign_id IS DISTINCT FROM OLD.campaign_id
        OR NEW.snapshot_id IS DISTINCT FROM OLD.snapshot_id
        OR NEW.schedule_id IS DISTINCT FROM OLD.schedule_id
        OR NEW.schedule_hash IS DISTINCT FROM OLD.schedule_hash
        OR NEW.scheduled_approval_id IS DISTINCT FROM OLD.scheduled_approval_id
        OR NEW.evaluated_approval_id IS DISTINCT FROM OLD.evaluated_approval_id
        OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at
    THEN
        RAISE EXCEPTION 'campaign schedule due claim identity evidence is immutable';
    END IF;

    IF OLD.state = 'emitted' OR NEW.version <> OLD.version + 1 THEN
        RAISE EXCEPTION 'campaign schedule due claim transition is not monotonic';
    END IF;

    IF NEW.state = 'leased' THEN
        IF OLD.state <> 'leased'
            OR NEW.attempt_number <> OLD.attempt_number + 1
            OR NEW.lease_token_hash IS NOT DISTINCT FROM OLD.lease_token_hash
        THEN
            RAISE EXCEPTION 'campaign schedule stale lease takeover is invalid';
        END IF;
    ELSIF NEW.state = 'emitted' THEN
        IF OLD.state <> 'leased'
            OR NEW.attempt_number <> OLD.attempt_number
            OR NEW.lease_owner IS DISTINCT FROM OLD.lease_owner
            OR NEW.lease_token_hash IS DISTINCT FROM OLD.lease_token_hash
            OR NEW.lease_expires_at IS DISTINCT FROM OLD.lease_expires_at
        THEN
            RAISE EXCEPTION 'campaign schedule emitted transition changed lease evidence';
        END IF;
    ELSE
        RAISE EXCEPTION 'campaign schedule due claim state is invalid';
    END IF;

    RETURN NEW;
END;
$claim$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_due_claims_coordination ON campaign_schedule_due_claims;');
            DB::unprepared('CREATE TRIGGER campaign_schedule_due_claims_coordination BEFORE UPDATE OR DELETE ON campaign_schedule_due_claims FOR EACH ROW EXECUTE FUNCTION enforce_campaign_schedule_due_claim_coordination();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_due_claims_immutable_identity BEFORE UPDATE ON campaign_schedule_due_claims WHEN NEW.id IS NOT OLD.id OR NEW.workspace_id IS NOT OLD.workspace_id OR NEW.campaign_id IS NOT OLD.campaign_id OR NEW.snapshot_id IS NOT OLD.snapshot_id OR NEW.schedule_id IS NOT OLD.schedule_id OR NEW.schedule_hash IS NOT OLD.schedule_hash OR NEW.scheduled_approval_id IS NOT OLD.scheduled_approval_id OR NEW.evaluated_approval_id IS NOT OLD.evaluated_approval_id OR NEW.claimed_at IS NOT OLD.claimed_at BEGIN SELECT RAISE(ABORT, 'campaign schedule due claim identity evidence is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_due_claims_monotonic BEFORE UPDATE ON campaign_schedule_due_claims WHEN OLD.state = 'emitted' OR NEW.version <> OLD.version + 1 OR (NEW.state = 'leased' AND (OLD.state <> 'leased' OR NEW.attempt_number <> OLD.attempt_number + 1 OR NEW.lease_token_hash IS OLD.lease_token_hash)) OR (NEW.state = 'emitted' AND (OLD.state <> 'leased' OR NEW.attempt_number <> OLD.attempt_number OR NEW.lease_owner IS NOT OLD.lease_owner OR NEW.lease_token_hash IS NOT OLD.lease_token_hash OR NEW.lease_expires_at IS NOT OLD.lease_expires_at)) OR NEW.state NOT IN ('leased', 'emitted') BEGIN SELECT RAISE(ABORT, 'campaign schedule due claim transition is not monotonic'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_due_claims_no_delete BEFORE DELETE ON campaign_schedule_due_claims BEGIN SELECT RAISE(ABORT, 'campaign schedule due claim coordination evidence cannot be deleted'); END;");
        }
    }

    private function dropClaimCoordinationGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_due_claims_coordination ON campaign_schedule_due_claims;');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_campaign_schedule_due_claim_coordination();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_due_claims_immutable_identity;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_due_claims_monotonic;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_due_claims_no_delete;');
        }
    }

    private function createIntentImmutabilityGuard(): void
    {
        if (! Schema::hasTable('campaign_schedule_execution_intents')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_schedule_execution_intent_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign schedule execution intent history is immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_execution_intents_immutable ON campaign_schedule_execution_intents;');
            DB::unprepared('CREATE TRIGGER campaign_schedule_execution_intents_immutable BEFORE UPDATE OR DELETE ON campaign_schedule_execution_intents FOR EACH ROW EXECUTE FUNCTION reject_campaign_schedule_execution_intent_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_execution_intents_immutable_update BEFORE UPDATE ON campaign_schedule_execution_intents BEGIN SELECT RAISE(ABORT, 'campaign schedule execution intent history is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_execution_intents_immutable_delete BEFORE DELETE ON campaign_schedule_execution_intents BEGIN SELECT RAISE(ABORT, 'campaign schedule execution intent history is immutable'); END;");
        }
    }

    private function dropIntentImmutabilityGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_execution_intents_immutable ON campaign_schedule_execution_intents;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_schedule_execution_intent_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_execution_intents_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_execution_intents_immutable_delete;');
        }
    }
};
