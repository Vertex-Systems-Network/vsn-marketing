<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journeys', function (Blueprint $table): void {
            if (! Schema::hasColumn('journeys', 'draft_graph')) {
                $table->json('draft_graph')->nullable();
            }
            if (! Schema::hasColumn('journeys', 'draft_revision')) {
                $table->unsignedInteger('draft_revision')->default(0);
            }
            if (! Schema::hasColumn('journeys', 'lifecycle_revision')) {
                $table->unsignedInteger('lifecycle_revision')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journeys')) {
            return;
        }
        $stateColumns = array_values(array_filter(
            ['draft_graph', 'draft_revision', 'lifecycle_revision'],
            static fn (string $column): bool => Schema::hasColumn('journeys', $column),
        ));
        if ($stateColumns !== [] && DB::table('journeys')->where(function ($query) use ($stateColumns): void {
            foreach ($stateColumns as $index => $column) {
                if ($column === 'draft_graph') {
                    $index === 0 ? $query->whereNotNull($column) : $query->orWhereNotNull($column);
                } else {
                    $index === 0 ? $query->where($column, '>', 0) : $query->orWhere($column, '>', 0);
                }
            }
        })->exists()) {
            throw new RuntimeException('Journey draft and lifecycle rollback refused while persisted journey state exists.');
        }
        Schema::table('journeys', function (Blueprint $table): void {
            $drop = array_values(array_filter(
                ['draft_graph', 'draft_revision', 'lifecycle_revision'],
                static fn (string $column): bool => Schema::hasColumn('journeys', $column),
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
