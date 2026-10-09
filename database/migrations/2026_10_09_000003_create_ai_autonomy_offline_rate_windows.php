<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zero rows means deny. These are independent owner-configured rate
        // limits and cannot be created or raised by model instructions.
        Schema::create('ai_autonomy_workspace_rate_windows', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->date('period_utc');
            $table->string('policy_version', 64);
            $table->unsignedInteger('max_attempts_per_minute');
            $table->unsignedBigInteger('window_started_unix')->default(0);
            $table->unsignedInteger('window_used_attempts')->default(0);
            $table->timestamps();
            $table->primary(['workspace_id', 'period_utc']);
            $table->foreign(['workspace_id', 'period_utc'])
                ->references(['workspace_id', 'period_utc'])
                ->on('ai_autonomy_workspace_quotas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_autonomy_workspace_rate_windows')
            && DB::table('ai_autonomy_workspace_rate_windows')->exists()) {
            throw new RuntimeException('Refusing destructive offline rate evidence rollback without restore approval.');
        }

        Schema::dropIfExists('ai_autonomy_workspace_rate_windows');
    }
};
