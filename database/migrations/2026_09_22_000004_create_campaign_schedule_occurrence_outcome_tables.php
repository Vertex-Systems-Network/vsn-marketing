<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_schedule_occurrence_outcomes')) {
            Schema::create('campaign_schedule_occurrence_outcomes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->uuid('schedule_id');
                $table->char('schedule_hash', 64);
                $table->uuid('scheduled_approval_id');
                $table->uuid('evaluated_decision_id')->nullable();
                $table->string('missed_reason', 64);
                $table->string('approval_invalid_reason', 64)->nullable();
                $table->string('approval_detail', 1000)->nullable();
                $table->timestampTz('resolved_at_utc');
                $table->timestampTz('observed_at');
                $table->string('recorded_by_actor_id', 191);
                $table->string('idempotency_key', 191);
                $table->char('outcome_hash', 64);

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['campaign_id', 'workspace_id'],
                    'campaign_schedule_outcome_campaign_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(
                    ['snapshot_id', 'workspace_id'],
                    'campaign_schedule_outcome_snapshot_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(
                    ['schedule_id', 'workspace_id'],
                    'campaign_schedule_outcome_schedule_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();
                $table->foreign(
                    ['scheduled_approval_id', 'workspace_id'],
                    'campaign_schedule_outcome_approval_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();
                $table->foreign(
                    ['evaluated_decision_id', 'workspace_id'],
                    'campaign_schedule_outcome_evaluated_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'campaign_schedule_outcome_id_workspace_uq');
                $table->unique(
                    ['workspace_id', 'idempotency_key'],
                    'campaign_schedule_outcome_workspace_idempotency_uq',
                );
                $table->unique(
                    ['workspace_id', 'schedule_id'],
                    'campaign_schedule_outcome_schedule_terminal_uq',
                );
                $table->index(
                    ['workspace_id', 'campaign_id', 'observed_at'],
                    'campaign_schedule_outcome_campaign_history_idx',
                );
            });
        }

        $this->createImmutabilityGuard();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuard();
        Schema::dropIfExists('campaign_schedule_occurrence_outcomes');
    }

    private function createImmutabilityGuard(): void
    {
        if (! Schema::hasTable('campaign_schedule_occurrence_outcomes')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_schedule_occurrence_outcome_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign schedule occurrence outcome history is immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_occurrence_outcomes_immutable ON campaign_schedule_occurrence_outcomes;');
            DB::unprepared('CREATE TRIGGER campaign_schedule_occurrence_outcomes_immutable BEFORE UPDATE OR DELETE ON campaign_schedule_occurrence_outcomes FOR EACH ROW EXECUTE FUNCTION reject_campaign_schedule_occurrence_outcome_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_occurrence_outcomes_immutable_update BEFORE UPDATE ON campaign_schedule_occurrence_outcomes BEGIN SELECT RAISE(ABORT, 'campaign schedule occurrence outcome history is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_occurrence_outcomes_immutable_delete BEFORE DELETE ON campaign_schedule_occurrence_outcomes BEGIN SELECT RAISE(ABORT, 'campaign schedule occurrence outcome history is immutable'); END;");
        }
    }

    private function dropImmutabilityGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_occurrence_outcomes_immutable ON campaign_schedule_occurrence_outcomes;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_schedule_occurrence_outcome_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_occurrence_outcomes_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_occurrence_outcomes_immutable_delete;');
        }
    }
};
