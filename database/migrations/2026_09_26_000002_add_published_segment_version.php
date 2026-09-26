<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('segment_definitions', 'published_version_number')) {
            return;
        }
        Schema::table('segment_definitions', function (Blueprint $table): void {
            $table->unsignedInteger('published_version_number')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('segment_definitions', 'published_version_number')) {
            return;
        }
        Schema::table('segment_definitions', function (Blueprint $table): void {
            $table->dropColumn('published_version_number');
        });
    }
};
