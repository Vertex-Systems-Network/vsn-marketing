<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiment_layer_keys', function (Blueprint $table): void {
            $table->char('scope_key', 64)->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('layer', 64);
            $table->string('unit_kind', 16);
            $table->char('key_fingerprint', 64);
            $table->timestampTz('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->index(['workspace_id', 'brand_id', 'layer'], 'experiment_layer_scope_idx');
        });
        Schema::create('experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('layer', 64);
            $table->string('unit_kind', 16);
            $table->string('control_variant', 64);
            $table->string('holdout_variant', 64)->nullable();
            $table->json('allocation');
            $table->char('plan_hash', 64);
            $table->char('key_fingerprint', 64);
            $table->string('status', 16);
            $table->string('created_by_actor_id', 191);
            $table->string('approved_by_actor_id', 191)->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'experiment_workspace_uq');
            $table->index(['workspace_id', 'brand_id', 'status'], 'experiment_scope_idx');
        });
        Schema::create('experiment_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('experiment_id');
            $table->string('layer', 64);
            $table->char('subject_key', 64);
            $table->string('variant', 64);
            $table->char('plan_hash', 64);
            $table->timestampTz('assigned_at');
            $table->foreign(['experiment_id', 'workspace_id'], 'assignment_experiment_scope_fk')->references(['id', 'workspace_id'])->on('experiments')->restrictOnDelete();
            $table->unique(['workspace_id', 'experiment_id', 'subject_key'], 'assignment_unit_uq');
            $table->unique(['workspace_id', 'layer', 'subject_key'], 'assignment_layer_uq');
            $table->unique(['id', 'workspace_id'], 'assignment_workspace_uq');
        });
        Schema::create('experiment_exposures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('assignment_id');
            $table->string('treatment_reference', 191);
            $table->string('variant', 64);
            $table->timestampTz('exposed_at');
            $table->foreign(['assignment_id', 'workspace_id'], 'exposure_assignment_scope_fk')->references(['id', 'workspace_id'])->on('experiment_assignments')->restrictOnDelete();
            $table->unique(['workspace_id', 'assignment_id', 'treatment_reference'], 'exposure_attempt_uq');
        });
    }

    public function down(): void
    {
        foreach (['experiment_exposures', 'experiment_assignments', 'experiments', 'experiment_layer_keys'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Refusing to drop nonempty experiment evidence without approved export/restore.');
            }
        }
        Schema::dropIfExists('experiment_exposures');
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('experiment_layer_keys');
    }
};
