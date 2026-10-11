<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_autonomy_offline_canary_reviews')) {
            throw new RuntimeException('Canonical frozen canary receipt table is unavailable.');
        }
        if (Schema::hasColumn('ai_autonomy_offline_canary_reviews', 'assignment_arm_set_sha256')) {
            return;
        }

        // Additive and nullable only for preserved historical receipts.
        // Never synthesize a per-arm commitment from incomplete legacy totals.
        Schema::table('ai_autonomy_offline_canary_reviews', function (Blueprint $table): void {
            $table->char('assignment_arm_set_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_autonomy_offline_canary_reviews')
            || ! Schema::hasColumn('ai_autonomy_offline_canary_reviews', 'assignment_arm_set_sha256')) {
            return;
        }
        if (DB::table('ai_autonomy_offline_canary_reviews')->exists()) {
            throw new RuntimeException('Cannot remove frozen arm commitments while immutable canary evidence exists.');
        }

        Schema::table('ai_autonomy_offline_canary_reviews', function (Blueprint $table): void {
            $table->dropColumn('assignment_arm_set_sha256');
        });
    }
};
