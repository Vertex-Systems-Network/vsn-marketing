<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_experiment_bindings')) {
            Schema::create('campaign_experiment_bindings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('experiment_id');
                $table->uuid('snapshot_id');
                $table->char('snapshot_hash', 64);
                $table->char('plan_hash', 64);
                $table->char('matrix_hash', 64);
                $table->json('variants');
                $table->string('status', 16);
                $table->timestamps();
                $table->foreign(['experiment_id', 'workspace_id'], 'campaign_exp_experiment_fk')->references(['id', 'workspace_id'])->on('experiments')->restrictOnDelete();
                $table->foreign(['snapshot_id', 'workspace_id'], 'campaign_exp_snapshot_fk')->references(['id', 'workspace_id'])->on('campaign_snapshots')->restrictOnDelete();
                $table->unique(['workspace_id', 'experiment_id'], 'campaign_exp_one_binding_uq');
                $table->unique(['id', 'workspace_id'], 'campaign_exp_workspace_uq');
            });
        }
        if (! Schema::hasTable('campaign_experiment_outcomes')) {
            Schema::create('campaign_experiment_outcomes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('binding_id');
                $table->uuid('assignment_id');
                $table->string('event_reference', 191);
                $table->string('treatment_reference', 191);
                $table->string('variant', 64);
                $table->string('state', 16);
                $table->string('reason', 64)->nullable();
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at');
                $table->foreign(['binding_id', 'workspace_id'], 'campaign_outcome_binding_fk')->references(['id', 'workspace_id'])->on('campaign_experiment_bindings')->restrictOnDelete();
                $table->foreign(['assignment_id', 'workspace_id'], 'campaign_outcome_assignment_fk')->references(['id', 'workspace_id'])->on('experiment_assignments')->restrictOnDelete();
                $table->unique(['workspace_id', 'event_reference'], 'campaign_outcome_event_uq');
                $table->index(['workspace_id', 'binding_id', 'state'], 'campaign_outcome_review_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['campaign_experiment_outcomes', 'campaign_experiment_bindings'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Refusing destructive experiment evidence rollback.');
            }
        }
        Schema::dropIfExists('campaign_experiment_outcomes');
        Schema::dropIfExists('campaign_experiment_bindings');
    }
};
