<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_meter_entitlements', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('meter');
            $table->unsignedBigInteger('limit_units');
            $table->unsignedBigInteger('used_units')->default(0);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->primary(['workspace_id', 'meter']);
        });

        Schema::create('workspace_meter_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('meter');
            $table->char('source_fingerprint', 64);
            $table->unsignedBigInteger('units');
            $table->timestamps();
            $table->unique(['workspace_id', 'meter', 'source_fingerprint'], 'meter_event_unique');
        });

        Schema::create('workspace_meter_change_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('meter', 80);
            $table->unsignedBigInteger('limit_units');
            $table->boolean('enabled');
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_meter_change_proposals');
        Schema::dropIfExists('workspace_meter_events');
        Schema::dropIfExists('workspace_meter_entitlements');
    }
};
