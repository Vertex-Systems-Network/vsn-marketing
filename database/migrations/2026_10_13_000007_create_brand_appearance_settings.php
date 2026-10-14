<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_appearance_settings', function (Blueprint $table): void {
            $table->uuid('brand_id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('display_name', 80);
            $table->char('accent_color', 7);
            $table->uuid('logo_asset_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            $table->foreign(['logo_asset_id', 'workspace_id'], 'brand_logo_workspace_fk')
                ->references(['id', 'workspace_id'])->on('assets')->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_appearance_settings');
    }
};
