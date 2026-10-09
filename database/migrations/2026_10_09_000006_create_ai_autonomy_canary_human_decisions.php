<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never auto-approve or seed a decision. A future authenticated
        // independent human writer must provide the session attestation.
        Schema::create('ai_autonomy_canary_human_decisions', function (Blueprint $table): void {
            $table->uuid('decision_id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('experiment_id');
            $table->unsignedInteger('sequence');
            $table->char('plan_sha256', 64);
            $table->char('analysis_sha256', 64);
            $table->uuid('cohort_receipt_id');
            $table->char('outcome_manifest_sha256', 64);
            $table->uuid('deciding_actor_id');
            $table->string('outcome', 16);
            $table->string('policy_version', 64);
            $table->boolean('human_session_verified')->default(false);
            $table->char('session_proof_sha256', 64);
            $table->unsignedBigInteger('observed_at_unix');
            $table->unsignedBigInteger('expires_at_unix');
            $table->timestamp('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique(['workspace_id', 'experiment_id', 'sequence'], 'ai_canary_human_decision_seq_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_canary_human_decisions')
            && DB::table('ai_autonomy_canary_human_decisions')->exists()) {
            throw new RuntimeException('Cannot silently delete immutable human canary decisions.');
        }

        Schema::dropIfExists('ai_autonomy_canary_human_decisions');
    }
};
