<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_subscriptions', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->string('status', 24);
            $table->string('provider_customer_id', 120)->nullable()->unique();
            $table->timestampTz('period_started_at');
            $table->timestamps();
        });

        Schema::create('usage_meter_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('metric', 40);
            $table->unsignedBigInteger('quantity');
            $table->string('idempotency_key', 191);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'metric', 'occurred_at']);
        });

        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->string('provider_event_id', 191)->primary();
            $table->uuid('workspace_id')->nullable();
            $table->string('type', 120);
            $table->string('outcome', 40);
            $table->timestampTz('created_at');
        });

        Schema::create('plan_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('from_plan', 40);
            $table->string('to_plan', 40);
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_requests');
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('usage_meter_events');
        Schema::dropIfExists('workspace_subscriptions');
    }
};
