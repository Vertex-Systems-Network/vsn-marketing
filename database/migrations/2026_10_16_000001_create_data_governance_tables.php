<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_data_policies', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->string('region', 16);
            $table->unsignedInteger('event_retention_days')->nullable();
            $table->timestamps();
        });

        Schema::create('data_legal_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('contact_id')->nullable();
            $table->string('reason', 255);
            $table->foreignUuid('placed_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'contact_id', 'released_at']);
        });

        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('contact_id');
            $table->string('type', 16);
            $table->string('status', 24);
            $table->json('result')->nullable();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('data_legal_holds');
        Schema::dropIfExists('workspace_data_policies');
    }
};
