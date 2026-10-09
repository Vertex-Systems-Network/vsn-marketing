<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No global authority row or workspace quota is auto-created. Missing
        // rows deny all reservations; the model cannot bootstrap privileges.
        Schema::create('ai_autonomy_global_stops', function (Blueprint $table): void {
            $table->string('id', 16)->primary();
            $table->boolean('stopped')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_autonomy_workspace_quotas', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->date('period_utc');
            $table->string('policy_version', 64);
            $table->boolean('workspace_stopped')->default(true);
            $table->unsignedInteger('max_actions');
            $table->unsignedBigInteger('max_tokens');
            $table->unsignedBigInteger('max_volume');
            $table->unsignedBigInteger('max_cost_minor');
            $table->unsignedInteger('max_attempts');
            $table->unsignedInteger('used_actions')->default(0);
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('used_volume')->default(0);
            $table->unsignedBigInteger('reserved_cost_minor')->default(0);
            $table->unsignedBigInteger('spent_cost_minor')->default(0);
            $table->unsignedInteger('used_attempts')->default(0);
            $table->timestamp('policy_expires_at');
            $table->timestamps();
            $table->primary(['workspace_id', 'period_utc']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });

        Schema::create('ai_autonomy_offline_reservations', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->date('period_utc');
            $table->string('run_id', 64);
            $table->uuid('brand_id')->nullable();
            $table->string('actor_id', 64);
            $table->char('snapshot_sha256', 64);
            $table->string('policy_version', 64);
            $table->unsignedInteger('actions');
            $table->unsignedBigInteger('tokens');
            $table->unsignedBigInteger('volume');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedInteger('attempts');
            $table->string('status', 32);
            $table->timestamps();
            $table->primary(['workspace_id', 'run_id']);
            $table->foreign(['workspace_id', 'period_utc'])
                ->references(['workspace_id', 'period_utc'])
                ->on('ai_autonomy_workspace_quotas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Production evidence must never be silently deleted by rollback.
        foreach (['ai_autonomy_offline_reservations', 'ai_autonomy_workspace_quotas', 'ai_autonomy_global_stops'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Refusing destructive autonomy safety evidence rollback without restore approval.');
            }
        }

        Schema::dropIfExists('ai_autonomy_offline_reservations');
        Schema::dropIfExists('ai_autonomy_workspace_quotas');
        Schema::dropIfExists('ai_autonomy_global_stops');
    }
};
