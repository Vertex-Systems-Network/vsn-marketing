<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('workspace_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('workspace_role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('workspace_role_id')->constrained('workspace_roles')->cascadeOnDelete();
            $table->string('permission');
            $table->timestamps();
            $table->primary(['workspace_role_id', 'permission']);
        });

        Schema::create('workspace_membership_roles', function (Blueprint $table): void {
            $table->foreignUuid('workspace_membership_id')->constrained('workspace_memberships')->cascadeOnDelete();
            $table->foreignUuid('workspace_role_id')->constrained('workspace_roles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['workspace_membership_id', 'workspace_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_membership_roles');
        Schema::dropIfExists('workspace_role_permissions');
        Schema::dropIfExists('workspace_roles');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
