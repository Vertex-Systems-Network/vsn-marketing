<?php

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Recovery\TenantBackup;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function recoveryWorkspace(string $slug, int $contacts = 3): string
{
    $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    $id = (string) Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => $slug, 'slug' => $slug])->getKey();
    $list = (string) Str::uuid();
    DB::table('contact_lists')->insert(['id' => $list, 'workspace_id' => $id, 'name' => 'All', 'created_at' => now(), 'updated_at' => now()]);
    for ($i = 0; $i < $contacts; $i++) {
        $contact = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $id, 'first_name' => 'C'.$i,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contact_identities')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $id, 'contact_id' => $contact,
            'type' => 'email', 'value' => "c{$i}@{$slug}.test", 'normalized_value' => "c{$i}@{$slug}.test",
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contact_list_memberships')->insert(['workspace_id' => $id, 'list_id' => $list, 'contact_id' => $contact, 'created_at' => now()]);
    }

    return $id;
}

function recoveryKey(): string
{
    return base64_encode(random_bytes(32));
}

function wipeTenant(string $workspaceId): void
{
    foreach (array_reverse(TenantBackup::TABLES) as $table) {
        if ($table !== 'consent_records') {
            DB::table($table)->where('workspace_id', $workspaceId)->delete();
        }
    }
}

it('restores an encrypted tenant backup with integrity and audit linkage, idempotently', function (): void {
    $workspace = recoveryWorkspace('one');
    $other = recoveryWorkspace('two');
    app(AuditRecorder::class)->record($workspace, 'test.before_backup', []);
    $backup = app(TenantBackup::class);
    $key = recoveryKey();
    $blob = $backup->create($workspace, $key);
    expect($blob)->not->toContain('c0@one.test');
    expect(base64_decode($blob))->not->toContain('c0@one.test');

    wipeTenant($workspace);
    $result = $backup->restore($blob, $key, $workspace);
    expect($result['restored']['contacts'])->toBe(3)->and($result['restored']['contact_list_memberships'])->toBe(3);
    expect(DB::table('contacts')->where('workspace_id', $workspace)->count())->toBe(3);
    expect(DB::table('contacts')->where('workspace_id', $other)->count())->toBe(3);

    // Replaying the same restore (e.g. a retried job) adds nothing.
    expect(array_sum($backup->restore($blob, $key, $workspace)['restored']))->toBe(0);
    $restoredAudit = DB::table('audit_events')->where('workspace_id', $workspace)->where('action', 'recovery.backup.restored')->first();
    expect((string) $restoredAudit->evidence)->toContain('source_audit_head');
});

it('refuses lost or wrong keys, cross-tenant targets and tampered backups without partial restore', function (): void {
    $workspace = recoveryWorkspace('one');
    $other = recoveryWorkspace('two');
    $backup = app(TenantBackup::class);
    $key = recoveryKey();
    $blob = $backup->create($workspace, $key);
    wipeTenant($workspace);

    expect(fn () => $backup->restore($blob, recoveryKey(), $workspace))->toThrow(RuntimeException::class);
    expect(fn () => $backup->restore($blob, 'not-a-key', $workspace))->toThrow(RuntimeException::class);
    expect(fn () => $backup->restore($blob, $key, $other))->toThrow(RuntimeException::class);
    $raw = base64_decode($blob);
    $raw[40] = chr(ord($raw[40]) ^ 1);
    expect(fn () => $backup->restore(base64_encode($raw), $key, $workspace))->toThrow(RuntimeException::class);
    expect(DB::table('contacts')->where('workspace_id', $workspace)->count())->toBe(0);
    expect(DB::table('contacts')->where('workspace_id', $other)->count())->toBe(3);
});

it('rolls back a restore that fails part-way through', function (): void {
    $workspace = recoveryWorkspace('one');
    $backup = app(TenantBackup::class);
    $key = recoveryKey();
    $blob = $backup->create($workspace, $key);
    wipeTenant($workspace);
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'contact_list_memberships') && str_starts_with(strtolower($query->sql), 'insert')) {
            throw new RuntimeException('simulated disk failure');
        }
    });

    expect(fn () => $backup->restore($blob, $key, $workspace))->toThrow(RuntimeException::class);
    expect(DB::table('contacts')->where('workspace_id', $workspace)->count())->toBe(0);
    expect(DB::table('contact_identities')->where('workspace_id', $workspace)->count())->toBe(0);
});

it('surfaces post-backup external effects for reconciliation instead of replaying them', function (): void {
    $workspace = recoveryWorkspace('one');
    $backup = app(TenantBackup::class);
    $takenAt = time() - 10;
    $audit = app(AuditRecorder::class);
    $audit->record($workspace, 'delivery.message.sent', ['message' => 'm-1']);
    $audit->record($workspace, 'contact.updated', []);
    $effects = $backup->externalEffectsSince($workspace, $takenAt);
    expect(array_column($effects, 'action'))->toBe(['delivery.message.sent']);
});

it('records a measured recovery exercise against RPO 24h and RTO 4h', function (): void {
    $workspace = recoveryWorkspace('one', 50);
    $result = app(TenantBackup::class)->exercise($workspace, recoveryKey(), fn () => wipeTenant($workspace), 3600);

    expect($result['rows_before'])->toBeGreaterThan(100)
        ->and($result['rows_lost_after_restore'])->toBe(0)
        ->and($result['restore_elapsed_seconds'])->toBeLessThan(14400)
        ->and($result['rpo_met'])->toBeTrue()->and($result['rto_met'])->toBeTrue();
    expect(DB::table('recovery_exercises')->where('workspace_id', $workspace)->count())->toBe(1);
    fwrite(STDERR, 'RECOVERY_EXERCISE '.json_encode($result).PHP_EOL);
});
