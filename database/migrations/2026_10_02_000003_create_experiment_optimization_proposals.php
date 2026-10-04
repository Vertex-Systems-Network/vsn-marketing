<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('experiment_optimization_proposals')) {
            Schema::create('experiment_optimization_proposals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('binding_id');
                $table->char('binding_matrix_hash', 64);
                $table->char('proposal_hash', 64);
                $table->string('trace_id', 128);
                $table->json('proposal');
                $table->string('status', 24);
                $table->char('evaluation_hash', 64)->nullable();
                $table->string('created_by_actor_id', 191);
                $table->string('evaluated_by_actor_id', 191)->nullable();
                $table->string('reviewed_by_actor_id', 191)->nullable();
                $table->timestamps();
                $table->foreign(['binding_id', 'workspace_id'], 'optimization_binding_scope_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_experiment_bindings')->restrictOnDelete();
                $table->unique(['workspace_id', 'binding_id', 'proposal_hash'], 'optimization_candidate_uq');
                $table->unique(['workspace_id', 'trace_id'], 'optimization_trace_uq');
                $table->unique(['id', 'workspace_id'], 'optimization_workspace_uq');
                $table->index(['workspace_id', 'binding_id', 'status'], 'optimization_review_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('experiment_optimization_proposals')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty optimization evidence.');
        }
        Schema::dropIfExists('experiment_optimization_proposals');
    }
};
