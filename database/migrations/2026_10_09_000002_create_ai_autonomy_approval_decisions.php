<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No approvals or permission grants are seeded. Only separately
        // authenticated domain services may eventually append decisions.
        Schema::create('ai_autonomy_approval_decisions', function (Blueprint $table): void {
            $table->bigIncrements('decision_sequence');
            $table->uuid('id')->unique();
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
            $table->unsignedBigInteger('not_before_unix');
            $table->unsignedBigInteger('expires_at_unix');
            $table->unsignedBigInteger('approved_at_unix');
            $table->uuid('approver_id');
            $table->string('outcome', 16);
            $table->timestamps();
            $table->index(['workspace_id', 'run_id', 'approved_at_unix']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_approval_decisions')
            && DB::table('ai_autonomy_approval_decisions')->exists()) {
            throw new RuntimeException('Refusing to destroy autonomy approval evidence without verified restore authorization.');
        }

        Schema::dropIfExists('ai_autonomy_approval_decisions');
    }
};
