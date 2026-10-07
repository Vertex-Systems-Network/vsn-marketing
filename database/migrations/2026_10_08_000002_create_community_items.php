<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_items')) {
            if (! Schema::hasColumns('community_items', [
                'id', 'workspace_id', 'brand_id', 'provider_key', 'source_key', 'item_type',
                'author_hash', 'body', 'provenance_hash', 'verification_hash', 'fingerprint',
                'moderation_state', 'assigned_actor_id', 'proposal_text', 'proposal_state',
                'received_at', 'updated_at',
            ])) {
                throw new RuntimeException('Unexpected partial community inbox schema.');
            }

            return;
        }

        Schema::create('community_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('provider_key', 64);
            $table->char('source_key', 64)->unique();
            $table->string('item_type', 24);
            $table->char('author_hash', 64);
            $table->text('body');
            $table->char('provenance_hash', 64);
            $table->char('verification_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('moderation_state', 24);
            $table->uuid('assigned_actor_id')->nullable();
            $table->text('proposal_text')->nullable();
            $table->string('proposal_state', 24)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('updated_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(['brand_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('brands')->restrictOnDelete();
            $table->foreign('assigned_actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id', 'received_at'], 'community_scope_received_idx');
            $table->index(['workspace_id', 'moderation_state'], 'community_moderation_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('community_items') && DB::table('community_items')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty community inbox evidence.');
        }

        Schema::dropIfExists('community_items');
    }
};
