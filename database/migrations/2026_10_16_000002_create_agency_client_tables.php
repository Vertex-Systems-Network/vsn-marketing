<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_client_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->json('delegated_permissions');
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_workspace_id', 'client_workspace_id']);
        });

        Schema::create('brand_customizations', function (Blueprint $table): void {
            $table->foreignUuid('brand_id')->primary()->constrained('brands')->cascadeOnDelete();
            $table->uuid('workspace_id');
            $table->string('display_name', 80);
            $table->string('primary_color', 7);
            $table->string('logo_url', 2048)->nullable();
            $table->timestamps();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_customizations');
        Schema::dropIfExists('agency_client_links');
    }
};
