<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_client_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission');
            $table->timestamps();
            $table->unique(['agency_workspace_id', 'client_workspace_id', 'user_id', 'permission'], 'agency_client_scope_unique');
            $table->index(['client_workspace_id', 'user_id', 'permission'], 'agency_client_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_client_delegations');
    }
};
