<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_schedules')) {
            Schema::create('campaign_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->uuid('approval_id');
                $table->char('target_set_hash', 64);
                $table->string('strategy', 32);
                $table->string('timezone_id', 191);
                $table->string('local_scheduled_at', 19);
                $table->timestampTz('resolved_at_utc');
                $table->char('schedule_hash', 64);
                $table->string('idempotency_key', 191);
                $table->string('created_by_actor_id', 191);
                $table->timestampTz('created_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['campaign_id', 'workspace_id'], 'campaign_schedule_campaign_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(['snapshot_id', 'workspace_id'], 'campaign_schedule_snapshot_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(['approval_id', 'workspace_id'], 'campaign_schedule_approval_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'campaign_schedule_id_workspace_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_schedule_workspace_idempotency_uq');
                $table->index(['workspace_id', 'resolved_at_utc'], 'campaign_schedule_workspace_due_idx');
                $table->index(['workspace_id', 'campaign_id', 'snapshot_id'], 'campaign_schedule_snapshot_lookup_idx');
            });
        }

        $this->createImmutabilityGuard();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuard();
        Schema::dropIfExists('campaign_schedules');
    }

    private function createImmutabilityGuard(): void
    {
        if (! Schema::hasTable('campaign_schedules')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_schedule_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign schedules are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedules_immutable ON campaign_schedules;');
            DB::unprepared('CREATE TRIGGER campaign_schedules_immutable BEFORE UPDATE OR DELETE ON campaign_schedules FOR EACH ROW EXECUTE FUNCTION reject_campaign_schedule_mutation();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedules_immutable_update BEFORE UPDATE ON campaign_schedules BEGIN SELECT RAISE(ABORT, 'campaign schedules are immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedules_immutable_delete BEFORE DELETE ON campaign_schedules BEGIN SELECT RAISE(ABORT, 'campaign schedules are immutable'); END;");
        }
    }

    private function dropImmutabilityGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedules_immutable ON campaign_schedules;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_schedule_mutation();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedules_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedules_immutable_delete;');
        }
    }
};
