<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_context_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->uuid('run_id')->nullable();
            $table->string('source_kind', 32);
            $table->string('provenance_reference', 255);
            $table->string('revision', 128);
            $table->string('permission', 64);
            $table->string('classification', 32);
            $table->text('content');
            $table->timestamp('expires_at');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id', 'customer_id', 'run_id', 'expires_at'], 'ai_context_scope_expiry');
        });
    }

    public function down(): void
    {
        if (DB::table('ai_context_memories')->exists()) {
            throw new RuntimeException('Refusing to drop AI context provenance without an approved export and restore plan.');
        }
        Schema::dropIfExists('ai_context_memories');
    }
};
