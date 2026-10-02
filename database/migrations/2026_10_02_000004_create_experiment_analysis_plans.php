<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('experiment_analysis_plans')) {
            Schema::create('experiment_analysis_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('binding_id');
                $table->json('plan');
                $table->char('plan_hash', 64);
                $table->string('status', 16);
                $table->string('created_by_actor_id', 191);
                $table->string('approved_by_actor_id', 191)->nullable();
                $table->timestamps();
                $table->foreign(['binding_id', 'workspace_id'], 'analysis_binding_scope_fk')
                    ->references(['id', 'workspace_id'])->on('campaign_experiment_bindings')->restrictOnDelete();
                $table->unique(['workspace_id', 'binding_id'], 'analysis_one_plan_uq');
                $table->unique(['id', 'workspace_id'], 'analysis_workspace_uq');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('experiment_analysis_plans') && DB::table('experiment_analysis_plans')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty frozen analysis evidence.');
        }
        Schema::dropIfExists('experiment_analysis_plans');
    }
};
