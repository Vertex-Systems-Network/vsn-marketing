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

        Schema::create('ai_route_circuits', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->string('route_id', 128);
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('open_until')->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'route_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });

        Schema::create('ai_gateway_traces', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->string('attempt_id', 64);
            $table->string('trace_id', 128);
            $table->string('route_id', 128);
            $table->string('route_version', 128);
            $table->string('prompt_id', 128);
            $table->string('prompt_version', 128);
            $table->string('context_manifest_sha256', 64);
            $table->string('status', 24);
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'attempt_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('ai_gateway_traces')->exists() || DB::table('ai_route_circuits')->exists()
            || DB::table('ai_budget_reservations')->exists() || DB::table('ai_workspace_budgets')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty AI budget evidence; export and approve a restore plan first.');
        }

        Schema::dropIfExists('ai_gateway_traces');
        Schema::dropIfExists('ai_route_circuits');
        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_workspace_budgets');
    }
};
