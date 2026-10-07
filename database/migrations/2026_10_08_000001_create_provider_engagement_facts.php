<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_engagement_facts')) {
            $indexes = Schema::getIndexes('provider_engagement_facts');
            $primary = array_filter($indexes, fn (array $index): bool => $index['primary'] && $index['columns'] === ['id']);
            $sourceUnique = array_filter($indexes, fn (array $index): bool => $index['unique'] && $index['columns'] === ['source_key']);
            $foreignKeys = Schema::getForeignKeys('provider_engagement_facts');
            $workspaceForeign = array_filter($foreignKeys, fn (array $key): bool => $key['columns'] === ['workspace_id']
                && $key['foreign_table'] === 'workspaces' && $key['foreign_columns'] === ['id']);
            $brandForeign = array_filter($foreignKeys, fn (array $key): bool => $key['columns'] === ['brand_id', 'workspace_id']
                && $key['foreign_table'] === 'brands' && $key['foreign_columns'] === ['id', 'workspace_id']);
            if (! Schema::hasColumns('provider_engagement_facts', [
                'id', 'workspace_id', 'brand_id', 'provider_key', 'metric', 'metric_definition', 'value',
                'source_key', 'source_lineage_hash', 'fingerprint', 'observed_at', 'received_at',
                'expires_at', 'is_total_known', 'created_at',
            ]) || $primary === [] || $sourceUnique === [] || $workspaceForeign === [] || $brandForeign === []) {
                throw new RuntimeException('Unexpected partial provider engagement analytics schema.');
            }

            return;
        }

        Schema::create('provider_engagement_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('provider_key', 64);
            $table->string('metric', 120);
            $table->json('metric_definition');
            $table->unsignedBigInteger('value');
            $table->char('source_key', 64)->unique();
            $table->char('source_lineage_hash', 64);
            $table->char('fingerprint', 64);
            $table->timestampTz('observed_at');
            $table->timestampTz('received_at');
            $table->timestampTz('expires_at');
            $table->boolean('is_total_known')->default(false);
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(['brand_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('brands')->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id', 'observed_at'], 'provider_engagement_scope_idx');
            $table->index(['workspace_id', 'provider_key', 'metric'], 'provider_engagement_metric_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('provider_engagement_facts') && DB::table('provider_engagement_facts')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty provider engagement analytics evidence.');
        }

        Schema::dropIfExists('provider_engagement_facts');
    }
};
