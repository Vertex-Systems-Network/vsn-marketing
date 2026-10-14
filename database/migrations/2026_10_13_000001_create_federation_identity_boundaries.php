<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_subject_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->timestamps();
            $table->unique(['workspace_id', 'issuer', 'subject'], 'federation_subject_unique');
        });

        Schema::create('federation_assertion_replays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->char('assertion_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['workspace_id', 'assertion_fingerprint'], 'federation_replay_unique');
        });

        Schema::create('workspace_role_assignment_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('membership_id')->constrained('workspace_memberships')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('workspace_roles')->cascadeOnDelete();
            $table->foreignUuid('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('permissions_fingerprint', 64);
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_role_assignment_proposals');
        Schema::dropIfExists('federation_assertion_replays');
        Schema::dropIfExists('federation_subject_bindings');
    }
};
