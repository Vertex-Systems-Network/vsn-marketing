<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->unique(['id', 'workspace_id'], 'brands_id_workspace_uq');
        });
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->unique(['id', 'contact_id', 'workspace_id'], 'contact_identity_id_contact_workspace_uq');
        });

        Schema::create('delivery_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('brand_id')->nullable();
            $table->string('business_intent_key', 191);
            $table->string('intent_type', 32);
            $table->string('channel', 32);
            $table->json('content');
            $table->json('metadata');
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['brand_id', 'workspace_id'], 'delivery_message_brand_workspace_fk')
                ->references(['id', 'workspace_id'])->on('brands')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'delivery_message_id_workspace_uq');
            $table->unique(['workspace_id', 'business_intent_key'], 'delivery_message_business_intent_uq');
            $table->index(['workspace_id', 'brand_id'], 'delivery_message_workspace_brand_idx');
        });

        Schema::create('delivery_message_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('message_id');
            $table->unsignedBigInteger('version');
            $table->string('business_intent_key', 191);
            $table->string('intent_type', 32);
            $table->string('channel', 32);
            $table->json('content');
            $table->json('metadata');
            $table->char('content_hash', 64);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['message_id', 'workspace_id'], 'delivery_snapshot_message_workspace_fk')
                ->references(['id', 'workspace_id'])->on('delivery_messages')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'delivery_snapshot_id_workspace_uq');
            $table->unique(['message_id', 'version'], 'delivery_snapshot_message_version_uq');
            $table->index(['workspace_id', 'content_hash'], 'delivery_snapshot_workspace_hash_idx');
        });

        Schema::create('delivery_recipient_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('message_snapshot_id');
            $table->uuid('contact_id');
            $table->uuid('contact_identity_id');
            $table->string('channel', 32);
            $table->string('destination', 320);
            $table->string('normalized_destination', 320);
            $table->string('identity_provider', 120)->nullable();
            $table->string('identity_provider_reference', 191)->nullable();
            $table->timestampTz('identity_verified_at')->nullable();
            $table->char('content_hash', 64);
            $table->timestampTz('created_at');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['message_snapshot_id', 'workspace_id'], 'delivery_recipient_message_snapshot_fk')
                ->references(['id', 'workspace_id'])->on('delivery_message_snapshots')->restrictOnDelete();
            $table->foreign(['contact_id', 'workspace_id'], 'delivery_recipient_contact_workspace_fk')
                ->references(['id', 'workspace_id'])->on('contacts')->restrictOnDelete();
            $table->foreign(
                ['contact_identity_id', 'contact_id', 'workspace_id'],
                'delivery_recipient_identity_contact_workspace_fk',
            )->references(['id', 'contact_id', 'workspace_id'])->on('contact_identities')->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'delivery_recipient_id_workspace_uq');
            $table->index(['message_snapshot_id', 'contact_id'], 'delivery_recipient_message_contact_idx');
            $table->index(['workspace_id', 'content_hash'], 'delivery_recipient_workspace_hash_idx');
        });

        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();
        Schema::dropIfExists('delivery_recipient_snapshots');
        Schema::dropIfExists('delivery_message_snapshots');
        Schema::dropIfExists('delivery_messages');

        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->dropUnique('contact_identity_id_contact_workspace_uq');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_id_workspace_uq');
        });
    }

    private function createImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_delivery_snapshot_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'delivery execution snapshots are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            foreach (['delivery_message_snapshots', 'delivery_recipient_snapshots'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_delivery_snapshot_mutation();");
            }
        }

        if ($driver === 'sqlite') {
            foreach (['delivery_message_snapshots', 'delivery_recipient_snapshots'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'delivery execution snapshots are immutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'delivery execution snapshots are immutable'); END;");
            }
        }
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            foreach (['delivery_message_snapshots', 'delivery_recipient_snapshots'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS reject_delivery_snapshot_mutation();');
        }

        if ($driver === 'sqlite') {
            foreach (['delivery_message_snapshots', 'delivery_recipient_snapshots'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update;");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete;");
            }
        }
    }
};
