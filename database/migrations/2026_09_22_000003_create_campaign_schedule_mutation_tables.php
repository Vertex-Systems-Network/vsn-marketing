<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_schedule_mutations')) {
            Schema::create('campaign_schedule_mutations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->string('mutation_type', 32);
                $table->uuid('previous_schedule_id');
                $table->char('previous_schedule_hash', 64);
                $table->timestampTz('previous_resolved_at_utc');
                $table->uuid('replacement_schedule_id')->nullable();
                $table->char('replacement_schedule_hash', 64)->nullable();
                $table->timestampTz('replacement_resolved_at_utc')->nullable();
                $table->string('actor_id', 191);
                $table->string('reason', 1000);
                $table->string('idempotency_key', 191);
                $table->timestampTz('occurred_at');
                $table->char('mutation_hash', 64);

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(
                    ['campaign_id', 'workspace_id'],
                    'campaign_schedule_mutation_campaign_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(
                    ['previous_schedule_id', 'workspace_id'],
                    'campaign_schedule_mutation_previous_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();
                $table->foreign(
                    ['replacement_schedule_id', 'workspace_id'],
                    'campaign_schedule_mutation_replacement_workspace_fk',
                )->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();

                $table->unique(['id', 'workspace_id'], 'campaign_schedule_mutation_id_workspace_uq');
                $table->unique(
                    ['workspace_id', 'idempotency_key'],
                    'campaign_schedule_mutation_workspace_idempotency_uq',
                );
                $table->unique(
                    ['workspace_id', 'previous_schedule_id'],
                    'campaign_schedule_mutation_previous_terminal_uq',
                );
                $table->unique(
                    ['workspace_id', 'replacement_schedule_id'],
                    'campaign_schedule_mutation_replacement_lineage_uq',
                );
                $table->index(
                    ['workspace_id', 'campaign_id', 'occurred_at'],
                    'campaign_schedule_mutation_campaign_history_idx',
                );
            });
        }

        $this->createImmutabilityGuard();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuard();
        Schema::dropIfExists('campaign_schedule_mutations');
    }

    private function createImmutabilityGuard(): void
    {
        if (! Schema::hasTable('campaign_schedule_mutations')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_schedule_mutation_history_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign schedule mutation history is immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_mutations_immutable ON campaign_schedule_mutations;');
            DB::unprepared('CREATE TRIGGER campaign_schedule_mutations_immutable BEFORE UPDATE OR DELETE ON campaign_schedule_mutations FOR EACH ROW EXECUTE FUNCTION reject_campaign_schedule_mutation_history_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_mutations_immutable_update BEFORE UPDATE ON campaign_schedule_mutations BEGIN SELECT RAISE(ABORT, 'campaign schedule mutation history is immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_mutations_immutable_delete BEFORE DELETE ON campaign_schedule_mutations BEGIN SELECT RAISE(ABORT, 'campaign schedule mutation history is immutable'); END;");
        }
    }

    private function dropImmutabilityGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_mutations_immutable ON campaign_schedule_mutations;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_schedule_mutation_history_change();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_mutations_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_mutations_immutable_delete;');
        }
    }
};
