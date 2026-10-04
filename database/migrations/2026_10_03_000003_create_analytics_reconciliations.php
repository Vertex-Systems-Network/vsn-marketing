<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_reconciliations')) {
            $primary = array_filter(Schema::getIndexes('analytics_reconciliations'), fn (array $i): bool => $i['primary'] && $i['columns'] === ['id']);
            $foreign = array_filter(Schema::getForeignKeys('analytics_reconciliations'), fn (array $f): bool => $f['columns'] === ['workspace_id'] && $f['foreign_table'] === 'workspaces' && $f['foreign_columns'] === ['id']);
            if (! Schema::hasColumns('analytics_reconciliations', ['id', 'workspace_id', 'scope_key', 'input_hash', 'fingerprint', 'report', 'created_at']) || $primary === [] || $foreign === []) {
                throw new RuntimeException('Unexpected partial analytics reconciliation schema.');
            }

            return;
        }
        Schema::create('analytics_reconciliations', function (Blueprint $t): void {
            $t->char('id', 64)->primary();
            $t->uuid('workspace_id');
            $t->char('scope_key', 64);
            $t->char('input_hash', 64);
            $t->char('fingerprint', 64);
            $t->text('report');
            $t->timestampTz('created_at');
            $t->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $t->index(['workspace_id', 'scope_key', 'created_at'], 'analytics_quality_scope_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('analytics_reconciliations') && DB::table('analytics_reconciliations')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty analytics reconciliation evidence.');
        }
        Schema::dropIfExists('analytics_reconciliations');
    }
};
