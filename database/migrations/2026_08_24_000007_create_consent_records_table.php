<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('contact_id');
            $table->string('channel', 64);
            $table->string('purpose', 120);
            $table->string('source', 120);
            $table->string('decision', 16);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'consent_contact_workspace_fk')
                ->references(['id', 'workspace_id'])
                ->on('contacts')
                ->restrictOnDelete();
            $table->index(
                ['workspace_id', 'contact_id', 'channel', 'purpose', 'occurred_at'],
                'consent_effective_lookup_idx',
            );
            $table->unique(['id', 'workspace_id'], 'consent_id_workspace_uq');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_consent_record_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'consent_records are append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER consent_records_append_only
BEFORE UPDATE OR DELETE ON consent_records
FOR EACH ROW EXECUTE FUNCTION reject_consent_record_mutation();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_consent_record_mutation()');
        }
    }
};
