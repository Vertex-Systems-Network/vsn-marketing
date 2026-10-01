<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workspace_budgets', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->date('period_utc');
            $table->unsignedBigInteger('limit_minor');
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->timestamps();
            $table->primary(['workspace_id', 'period_utc']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });

        Schema::create('ai_budget_reservations', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->string('trace_id', 128);
            $table->date('period_utc');
            $table->unsignedBigInteger('reserved_minor');
            $table->unsignedBigInteger('actual_minor')->nullable();
            $table->string('status', 16);
            $table->timestamps();
            $table->primary(['workspace_id', 'trace_id']);
            $table->foreign(['workspace_id', 'period_utc'])->references(['workspace_id', 'period_utc'])->on('ai_workspace_budgets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('ai_budget_reservations')->exists() || DB::table('ai_workspace_budgets')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty AI budget evidence; export and approve a restore plan first.');
        }

        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_workspace_budgets');
    }
};
