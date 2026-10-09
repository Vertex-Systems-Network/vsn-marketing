<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only independent operator decisions; no default grants,
        // update endpoint or model-accessible authoring path.
        Schema::create('ai_autonomy_offline_approval_decisions', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('decision_id')->unique();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('run_id', 64);
            $table->char('snapshot_sha256', 64);
            $table->string('policy_version', 64);
            $table->char('audience_sha256', 64);
            $table->char('content_sha256', 64);
            $table->char('destination_sha256', 64);
            $table->unsignedBigInteger('max_cost_minor');
            $table->unsignedBigInteger('max_volume');
            $table->bigInteger('not_before_unix');
            $table->bigInteger('expires_at_unix');
            $table->bigInteger('approved_at_unix');
            $table->uuid('approver_id');
            $table->string('outcome', 16);
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'run_id', 'sequence'], 'autonomy_approval_latest');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_offline_approval_decisions')
            && DB::table('ai_autonomy_offline_approval_decisions')->exists()) {
            throw new RuntimeException('Refusing to delete independently signed-off approval history.');
        }

        Schema::dropIfExists('ai_autonomy_offline_approval_decisions');
    }
};
