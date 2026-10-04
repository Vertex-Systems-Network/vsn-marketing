<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingOperationState;
use App\Modules\Providers\Domain\Messaging\MessagingProviderOutcome;
use App\Modules\Providers\Infrastructure\Messaging\DatabaseMessagingOperationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function messagingPersistenceWorkspace(): string
{
    $suffix = strtolower(Str::random(12));
    $org = Organization::query()->create(['name' => 'Messaging', 'slug' => $suffix]);

    return (string) Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => 'Messaging', 'slug' => $suffix])->getKey();
}

it('replays a durable reservation and rejects payload channel and provider conflicts', function () {
    $r = new DatabaseMessagingOperationRepository;
    $workspace = messagingPersistenceWorkspace();
    $hash = hash('sha256', 'payload');
    $first = $r->reserve($workspace, 'sms', 'azure', 'same-key', $hash);
    expect($r->reserve($workspace, 'sms', 'azure', 'same-key', $hash)->id)->toBe($first->id);
    foreach ([['sms', 'azure', hash('sha256', 'changed')], ['push', 'azure', $hash], ['sms', 'other', $hash]] as [$channel, $provider, $fingerprint]) {
        expect(fn () => $r->reserve($workspace, $channel, $provider, 'same-key', $fingerprint))->toThrow(InvalidArgumentException::class);
    }
    $other = $r->reserve(messagingPersistenceWorkspace(), 'sms', 'azure', 'same-key', $hash);
    expect($other->id)->not->toBe($first->id)->and(DB::table('messaging_operations')->count())->toBe(2);
});

it('rejects forged tenant and provider scope before changing a durable operation', function () {
    $r = new DatabaseMessagingOperationRepository;
    $op = $r->reserve(messagingPersistenceWorkspace(), 'sms', 'azure', 'key', hash('sha256', 'payload'));
    $outcome = new MessagingProviderOutcome('provider-1', MessagingOperationState::Succeeded, ReconciliationSource::Polling, $op->createdAt->modify('+1 second'));
    foreach (['workspace', 'provider', 'fingerprint', 'channel', 'key'] as $field) {
        $forged = new MessagingOperation($op->id, $field === 'workspace' ? messagingPersistenceWorkspace() : $op->workspaceId,
            $field === 'channel' ? 'push' : $op->channel, $field === 'provider' ? 'other' : $op->providerKey,
            $field === 'key' ? 'other' : $op->idempotencyKey, $field === 'fingerprint' ? hash('sha256', 'other') : $op->requestFingerprint,
            $op->state, null, false, [], $op->createdAt, $op->updatedAt);
        expect(fn () => $r->reconcile($forged, $outcome))->toThrow(RuntimeException::class);
    }
    expect(DB::table('messaging_operations')->value('operation_state'))->toBe('reserved');
});

it('resolves an ambiguous hold with authoritative evidence and ignores stale observations', function () {
    $r = new DatabaseMessagingOperationRepository;
    $op = $r->reserve(messagingPersistenceWorkspace(), 'sms', 'azure', 'key', hash('sha256', 'payload'));
    $held = $r->reconcile($op, new MessagingProviderOutcome('provider-1', MessagingOperationState::Succeeded, ReconciliationSource::Polling, $op->createdAt->modify('+2 seconds'), ambiguous: true));
    $stale = $r->reconcile($op, new MessagingProviderOutcome('provider-1', MessagingOperationState::Failed, ReconciliationSource::Webhook, $op->createdAt->modify('+1 second')));
    expect($stale->state)->toBe(MessagingOperationState::Ambiguous)->and($stale->updatedAt)->toEqual($held->updatedAt);
    $done = $r->reconcile($op, new MessagingProviderOutcome('provider-1', MessagingOperationState::Succeeded, ReconciliationSource::Webhook, $op->createdAt->modify('+3 seconds')));
    expect($done->state)->toBe(MessagingOperationState::Succeeded)->and($done->ambiguousOutcome)->toBeFalse();
    expect(fn () => $r->reconcile($op, new MessagingProviderOutcome('provider-other', MessagingOperationState::Succeeded, ReconciliationSource::Polling, $op->createdAt->modify('+4 seconds'))))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->reconcile($op, new MessagingProviderOutcome('provider-1', MessagingOperationState::Failed, ReconciliationSource::Polling, $op->createdAt->modify('+4 seconds'))))->toThrow(InvalidArgumentException::class);
});

it('repairs a missing apply marker safely while refusing destructive evidence rollback', function () {
    $migration = require database_path('migrations/2026_10_04_000015_create_messaging_operations_table.php');
    $r = new DatabaseMessagingOperationRepository;
    $op = $r->reserve(messagingPersistenceWorkspace(), 'sms', 'azure', 'key', hash('sha256', 'payload'));
    $migration->up();
    expect(DB::table('messaging_operations')->value('id'))->toBe($op->id);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('messaging_operations')->value('id'))->toBe($op->id);
});

it('refuses an unknown partial messaging schema instead of marking it applied', function () {
    $migration = require database_path('migrations/2026_10_04_000015_create_messaging_operations_table.php');
    $migration->down();
    Schema::create('messaging_operations', fn ($table) => $table->uuid('id')->primary());
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(Schema::hasColumn('messaging_operations', 'workspace_id'))->toBeFalse();
    Schema::drop('messaging_operations');
    $migration->up();
});
