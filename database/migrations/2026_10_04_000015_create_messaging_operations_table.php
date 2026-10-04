<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('messaging_operations', function (Blueprint $table): void {
  $table->uuid('id')->primary(); $table->uuid('workspace_id'); $table->string('channel',64); $table->string('provider_key',120);
  $table->string('idempotency_key',191); $table->string('request_fingerprint',128); $table->string('operation_state',48);
  $table->string('provider_operation_id',191)->nullable(); $table->boolean('ambiguous_outcome')->default(false);
  $table->json('evidence'); $table->timestampsTz();
  $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
  $table->unique(['workspace_id','idempotency_key'],'messaging_operations_workspace_idem_uq');
  $table->index(['workspace_id','provider_key','channel'],'messaging_operations_provider_lookup_idx');
  $table->index(['workspace_id','operation_state'],'messaging_operations_state_lookup_idx');
 });}
 public function down(): void { Schema::dropIfExists('messaging_operations'); }
};