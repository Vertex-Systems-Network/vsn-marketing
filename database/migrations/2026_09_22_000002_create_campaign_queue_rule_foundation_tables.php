<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_schedule_rule_sets')) {
            Schema::create('campaign_schedule_rule_sets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('parent_rule_set_id')->nullable();
                $table->string('channel', 64);
                $table->unsignedInteger('version_number');
                $table->string('timezone_id', 191);
                $table->json('slots');
                $table->char('rule_hash', 64);
                $table->string('idempotency_key', 191);
                $table->string('created_by_actor_id', 191);
                $table->timestampTz('created_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_schedule_rule_id_workspace_uq');
                $table->unique(['workspace_id', 'channel', 'version_number'], 'campaign_schedule_rule_version_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_schedule_rule_idempotency_uq');
                $table->index(['workspace_id', 'channel', 'created_at'], 'campaign_schedule_rule_channel_idx');

                $table->foreign(
                    ['parent_rule_set_id', 'workspace_id'],
                    'campaign_schedule_rule_parent_workspace_fk',
                )->references(['id', 'workspace_id'])
                    ->on('campaign_schedule_rule_sets')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('campaign_queue_schedule_bindings')) {
            Schema::create('campaign_queue_schedule_bindings', function (Blueprint $table): void {
                $table->uuid('schedule_id');
                $table->uuid('workspace_id');
                $table->uuid('rule_set_id');
                $table->unsignedInteger('rule_version');
                $table->char('rule_hash', 64);
                $table->string('channel', 64);
                $table->timestampTz('created_at');

                $table->primary(['schedule_id', 'workspace_id'], 'campaign_queue_schedule_binding_pk');
                $table->foreign(['schedule_id', 'workspace_id'], 'campaign_queue_schedule_schedule_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_schedules')->restrictOnDelete();
                $table->foreign(['rule_set_id', 'workspace_id'], 'campaign_queue_schedule_rule_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_schedule_rule_sets')->restrictOnDelete();
            });
        }

        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();
        Schema::dropIfExists('campaign_queue_schedule_bindings');
        Schema::dropIfExists('campaign_schedule_rule_sets');
    }

    private function createImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_schedule_rule_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign schedule rules are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_rule_sets_immutable ON campaign_schedule_rule_sets;');
            DB::unprepared('CREATE TRIGGER campaign_schedule_rule_sets_immutable BEFORE UPDATE OR DELETE ON campaign_schedule_rule_sets FOR EACH ROW EXECUTE FUNCTION reject_campaign_schedule_rule_mutation();');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_queue_binding_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign queue schedule bindings are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_queue_schedule_bindings_immutable ON campaign_queue_schedule_bindings;');
            DB::unprepared('CREATE TRIGGER campaign_queue_schedule_bindings_immutable BEFORE UPDATE OR DELETE ON campaign_queue_schedule_bindings FOR EACH ROW EXECUTE FUNCTION reject_campaign_queue_binding_mutation();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_rule_sets_immutable_update BEFORE UPDATE ON campaign_schedule_rule_sets BEGIN SELECT RAISE(ABORT, 'campaign schedule rules are immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_schedule_rule_sets_immutable_delete BEFORE DELETE ON campaign_schedule_rule_sets BEGIN SELECT RAISE(ABORT, 'campaign schedule rules are immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_queue_schedule_bindings_immutable_update BEFORE UPDATE ON campaign_queue_schedule_bindings BEGIN SELECT RAISE(ABORT, 'campaign queue schedule bindings are immutable'); END;");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS campaign_queue_schedule_bindings_immutable_delete BEFORE DELETE ON campaign_queue_schedule_bindings BEGIN SELECT RAISE(ABORT, 'campaign queue schedule bindings are immutable'); END;");
        }
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_queue_schedule_bindings_immutable ON campaign_queue_schedule_bindings;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_queue_binding_mutation();');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_rule_sets_immutable ON campaign_schedule_rule_sets;');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_schedule_rule_mutation();');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_queue_schedule_bindings_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_queue_schedule_bindings_immutable_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_rule_sets_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS campaign_schedule_rule_sets_immutable_delete;');
        }
    }
};
