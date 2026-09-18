<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverability_observations', function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider_key', 120);
            $table->string('source', 191);
            $table->string('evidence_version', 120);
            $table->string('message_purpose', 64);
            $table->string('signal_kind', 64);
            $table->string('signal_key', 191);
            $table->text('signal_value');
            $table->text('provenance_reference');
            $table->string('replay_key', 191);
            $table->timestampTz('effective_at');
            $table->timestampTz('observed_at');
            $table->timestampTz('recorded_at');
            $table->timestampTz('fresh_until')->nullable();
            $table->boolean('trusted');

            $table->unique(
                ['workspace_id', 'replay_key'],
                'deliverability_obs_workspace_replay_uq',
            );
            $table->index(
                ['workspace_id', 'provider_key', 'message_purpose', 'observed_at'],
                'deliverability_obs_scope_time_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverability_observations');
    }
};
