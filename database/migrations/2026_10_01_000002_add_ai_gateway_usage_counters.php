<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_gateway_traces', function (Blueprint $table): void {
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('ai_gateway_traces')->whereNotNull('input_tokens')->orWhereNotNull('output_tokens')->exists()) {
            throw new RuntimeException('Refusing to drop AI usage evidence without an approved restore plan.');
        }
        Schema::table('ai_gateway_traces', function (Blueprint $table): void {
            $table->dropColumn(['input_tokens', 'output_tokens']);
        });
    }
};
