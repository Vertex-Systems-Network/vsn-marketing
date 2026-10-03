<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->verifyExisting('analytics_report_schedules', ['id', 'workspace_id', 'organization_id', 'brand_id', 'actor_id', 'kind', 'definition_hash', 'next_window_end', 'enabled', 'status_code', 'created_at'], ['id', 'workspace_id'], ['workspace_id'], 'workspaces', ['id']);
        $this->verifyExisting('analytics_report_runs', ['id', 'workspace_id', 'schedule_id', 'window_end', 'status', 'attempts', 'claim_token', 'lease_until', 'snapshot_id', 'failure_code', 'created_at'], ['schedule_id', 'window_end'], ['schedule_id', 'workspace_id'], 'analytics_report_schedules', ['id', 'workspace_id']);
        if (! Schema::hasTable('analytics_report_schedules')) {
            Schema::create('analytics_report_schedules', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('workspace_id');
                $t->uuid('organization_id');
                $t->uuid('brand_id')->nullable();
                $t->string('actor_id', 191);
                $t->string('kind', 32);
                $t->char('definition_hash', 64);
                $t->timestampTz('next_window_end');
                $t->boolean('enabled')->default(true);
                $t->string('status_code', 32)->nullable();
                $t->timestampTz('created_at');
                $t->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
                $t->unique(['id', 'workspace_id'], 'analytics_schedule_scope_uq');
                $t->index(['enabled', 'next_window_end'], 'analytics_schedule_due_idx');
            });
        }
        if (! Schema::hasTable('analytics_report_runs')) {
            Schema::create('analytics_report_runs', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('workspace_id');
                $t->uuid('schedule_id');
                $t->timestampTz('window_end');
                $t->string('status', 16);
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->uuid('claim_token')->nullable();
                $t->timestampTz('lease_until')->nullable();
                $t->uuid('snapshot_id')->nullable();
                $t->string('failure_code', 32)->nullable();
                $t->timestampTz('created_at');
                $t->foreign(['schedule_id', 'workspace_id'], 'analytics_run_schedule_fk')->references(['id', 'workspace_id'])->on('analytics_report_schedules')->restrictOnDelete();
                $t->unique(['schedule_id', 'window_end'], 'analytics_schedule_window_uq');
            });
        }
    }

    private function verifyExisting(string $table, array $columns, array $unique, array $foreign, string $target, array $targetColumns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $uniquePresent = array_filter(Schema::getIndexes($table), static fn (array $index): bool => $index['unique'] && $index['columns'] === $unique);
        $foreignPresent = array_filter(Schema::getForeignKeys($table), static fn (array $key): bool => $key['columns'] === $foreign && $key['foreign_table'] === $target && $key['foreign_columns'] === $targetColumns);
        if (! Schema::hasColumns($table, $columns) || $uniquePresent === [] || $foreignPresent === []) {
            throw new RuntimeException('Unexpected analytics schedule schema; reconcile partial migration before retry.');
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('analytics_report_schedules') && DB::table('analytics_report_schedules')->exists())
            || (Schema::hasTable('analytics_report_runs') && DB::table('analytics_report_runs')->exists())) {
            throw new RuntimeException('Refusing to drop nonempty analytics report evidence without approved recovery.');
        }
        Schema::dropIfExists('analytics_report_runs');
        Schema::dropIfExists('analytics_report_schedules');
    }
};
