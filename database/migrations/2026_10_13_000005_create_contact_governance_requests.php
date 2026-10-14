<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_legal_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->foreignUuid('issued_by')->constrained('users')->restrictOnDelete();
            $table->char('reason_fingerprint', 64);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->foreign(['contact_id', 'workspace_id'], 'governance_hold_contact_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'contact_id', 'released_at'], 'governance_hold_lookup');
        });

        Schema::create('contact_data_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('status', 32);
            $table->char('source_fingerprint', 64);
            $table->json('inventory_snapshot');
            $table->timestamps();
            $table->foreign(['contact_id', 'workspace_id'], 'governance_request_contact_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->restrictOnDelete();
            $table->unique(['workspace_id', 'action', 'source_fingerprint'], 'governance_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_data_requests');
        Schema::dropIfExists('contact_legal_holds');
    }
};
