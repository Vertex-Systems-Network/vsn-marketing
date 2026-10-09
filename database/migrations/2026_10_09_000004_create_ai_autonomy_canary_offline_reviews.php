<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable review-only evidence. Absent independent sources never
        // create rows, and a row does not authorize campaign enrollment.
        Schema::create('ai_autonomy_offline_canary_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('experiment_id');
            $table->uuid('brand_id')->nullable();
            $table->char('plan_sha256', 64);
            $table->char('assignment_manifest_sha256', 64);
            $table->unsignedBigInteger('assigned_denominator');
            $table->unsignedBigInteger('holdout_denominator');
            $table->string('status', 40);
            $table->string('recorded_by_actor_id', 191);
            $table->timestampTz('created_at');
            $table->unique(['workspace_id', 'experiment_id'], 'canary_once_per_frozen_plan_uq');
            $table->foreign(['experiment_id', 'workspace_id'], 'canary_review_experiment_fk')
                ->references(['id', 'workspace_id'])->on('experiments')->restrictOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_offline_canary_reviews')
            && DB::table('ai_autonomy_offline_canary_reviews')->exists()) {
            throw new RuntimeException('Refusing to delete offline canary evidence without approved restore/export.');
        }

        Schema::dropIfExists('ai_autonomy_offline_canary_reviews');
    }
};
