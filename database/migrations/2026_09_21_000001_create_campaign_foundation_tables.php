<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->string('name', 191);
                $table->string('status', 48);
                $table->unsignedBigInteger('state_version');
                $table->string('idempotency_key', 191);
                $table->string('created_by_actor_id', 191);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at')->nullable();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_id_workspace_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_workspace_idempotency_uq');
                $table->index(['workspace_id', 'status'], 'campaign_workspace_status_idx');
            });
        }

        if (! Schema::hasTable('campaign_snapshots')) {
            Schema::create('campaign_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('parent_snapshot_id')->nullable();
                $table->unsignedBigInteger('version_number');
                $table->unsignedInteger('schema_version');
                $table->uuid('content_version_id');
                $table->uuid('template_version_id')->nullable();
                $table->json('component_version_ids');
                $table->json('asset_reference_ids');
                $table->json('capability_evidence_ids');
                $table->json('brand_reference');
                $table->json('intended_execution');
                $table->char('target_set_hash', 64);
                $table->char('snapshot_hash', 64);
                $table->string('idempotency_key', 191);
                $table->string('created_by_actor_id', 191);
                $table->timestampTz('created_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['campaign_id', 'workspace_id'], 'campaign_snapshot_campaign_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(['content_version_id', 'workspace_id'], 'campaign_snapshot_content_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('content_versions')->restrictOnDelete();
                $table->foreign(['template_version_id', 'workspace_id'], 'campaign_snapshot_template_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('content_template_versions')->restrictOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_snapshot_id_workspace_uq');
                $table->foreign(['parent_snapshot_id', 'workspace_id'], 'campaign_snapshot_parent_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->unique(['campaign_id', 'version_number'], 'campaign_snapshot_campaign_version_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_snapshot_workspace_idempotency_uq');
                $table->index(['workspace_id', 'campaign_id', 'snapshot_hash'], 'campaign_snapshot_workspace_hash_idx');
            });
        }

        if (! Schema::hasTable('campaign_targets')) {
            Schema::create('campaign_targets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('snapshot_id');
                $table->string('kind', 48);
                $table->string('canonical_reference_id', 191);
                $table->string('channel', 32);
                $table->uuid('provider_connection_id')->nullable();
                $table->uuid('capability_evidence_id')->nullable();
                $table->json('metadata');
                $table->char('target_hash', 64);
                $table->timestampTz('created_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['snapshot_id', 'workspace_id'], 'campaign_target_snapshot_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->foreign(['provider_connection_id', 'workspace_id'], 'campaign_target_connection_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('provider_connections')->restrictOnDelete();
                $table->foreign(['capability_evidence_id', 'workspace_id'], 'campaign_target_capability_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('provider_capabilities')->restrictOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_target_id_workspace_uq');
                $table->unique(
                    ['snapshot_id', 'kind', 'canonical_reference_id', 'channel'],
                    'campaign_target_snapshot_identity_uq',
                );
                $table->index(['workspace_id', 'canonical_reference_id'], 'campaign_target_workspace_reference_idx');
            });
        }

        if (! Schema::hasTable('campaign_approval_decisions')) {
            Schema::create('campaign_approval_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id');
                $table->char('target_set_hash', 64);
                $table->string('outcome', 32);
                $table->string('actor_id', 191);
                $table->string('actor_role', 120);
                $table->text('reason')->nullable();
                $table->json('capability_evidence_ids');
                $table->uuid('supersedes_decision_id')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->string('idempotency_key', 191);
                $table->timestampTz('occurred_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['campaign_id', 'workspace_id'], 'campaign_approval_campaign_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(['snapshot_id', 'workspace_id'], 'campaign_approval_snapshot_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_approval_id_workspace_uq');
                $table->foreign(['supersedes_decision_id', 'workspace_id'], 'campaign_approval_supersedes_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_approval_decisions')->restrictOnDelete();
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_approval_workspace_idempotency_uq');
                $table->index(['workspace_id', 'campaign_id', 'snapshot_id'], 'campaign_approval_snapshot_lookup_idx');
            });
        }

        if (! Schema::hasTable('campaign_events')) {
            Schema::create('campaign_events', function (Blueprint $table): void {
                $table->bigIncrements('sequence');
                $table->uuid('id');
                $table->uuid('workspace_id');
                $table->uuid('campaign_id');
                $table->uuid('snapshot_id')->nullable();
                $table->string('event_type', 120);
                $table->string('actor_id', 191);
                $table->text('reason')->nullable();
                $table->string('from_status', 48)->nullable();
                $table->string('to_status', 48)->nullable();
                $table->json('evidence');
                $table->string('idempotency_key', 191);
                $table->timestampTz('occurred_at');

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign(['campaign_id', 'workspace_id'], 'campaign_event_campaign_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaigns')->restrictOnDelete();
                $table->foreign(['snapshot_id', 'workspace_id'], 'campaign_event_snapshot_workspace_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->unique(['id', 'workspace_id'], 'campaign_event_id_workspace_uq');
                $table->unique(['workspace_id', 'idempotency_key'], 'campaign_event_workspace_idempotency_uq');
                $table->index(['workspace_id', 'campaign_id', 'sequence'], 'campaign_event_history_idx');
            });
        }

        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();

        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_approval_decisions');
        Schema::dropIfExists('campaign_targets');
        Schema::dropIfExists('campaign_snapshots');
        Schema::dropIfExists('campaigns');
    }

    private function createImmutabilityGuards(): void
    {
        $tables = [
            'campaign_snapshots',
            'campaign_targets',
            'campaign_approval_decisions',
            'campaign_events',
        ];

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_history_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'campaign snapshots, targets, approvals and events are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table};");
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_campaign_history_mutation();");
            }
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_immutable_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'campaign snapshots, targets, approvals and events are immutable'); END;");
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_immutable_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'campaign snapshots, targets, approvals and events are immutable'); END;");
            }
        }
    }

    private function dropImmutabilityGuards(): void
    {
        $tables = [
            'campaign_snapshots',
            'campaign_targets',
            'campaign_approval_decisions',
            'campaign_events',
        ];

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table};");
                }
            }

            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_history_mutation();');
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update;");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete;");
            }
        }
    }
};
