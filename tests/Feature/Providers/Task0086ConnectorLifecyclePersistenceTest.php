<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Providers\Application\RegisterProvider;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCompatibilityAssessment;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorDeprecationObservation;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleDecision;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleHealth;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleReconciler;
use App\Modules\Providers\Domain\ConnectorFactory\Contracts\ConnectorLifecycleHealthRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function task0086LifecyclePersistenceFixture(): array
{
    $suffix = strtolower(Str::random(12));
    $organization = Organization::query()->create(['name' => 'Lifecycle '.$suffix, 'slug' => 'lifecycle-'.$suffix]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Lifecycle '.$suffix,
        'slug' => 'lifecycle-'.$suffix,
    ]);
    $workspaceId = (string) $workspace->getKey();
    $context = new TenantContext(
        organizationId: (string) $organization->getKey(),
        workspaceId: $workspaceId,
        brandId: null,
        actorId: 'lifecycle-test-'.$suffix,
    );
    $provider = app(RegisterProvider::class)->handle(
        $context,
        'example-'.$suffix,
        'Example provider',
        'https://docs.example.test/provider',
    );

    return ['workspace_id' => $workspaceId, 'provider_key' => $provider->key];
}

function task0086LifecycleHealth(string $workspaceId, string $providerKey, string $observedAt, string $key): ConnectorLifecycleHealth
{
    $at = new DateTimeImmutable($observedAt);
    $assessment = ConnectorCompatibilityAssessment::assess(
        $workspaceId,
        $providerKey,
        '1.2.0',
        '1.2.0',
        ['contacts.read' => '1.0.0'],
        ['contacts.read' => '1.0.0'],
        $at,
    );
    return (new ConnectorLifecycleReconciler)->reconcile(
        $assessment,
        null,
        null,
        $at,
        idempotencyKey: hash('sha256', $key),
    );
}

it('rejects evidence snapshots scoped to another workspace', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $other = task0086LifecyclePersistenceFixture();
    $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
    $assessment = ConnectorCompatibilityAssessment::assess(
        $other['workspace_id'],
        $other['provider_key'],
        '1.2.0',
        '1.2.0',
        ['contacts.read' => '1.0.0'],
        ['contacts.read' => '1.0.0'],
        $at,
    );

    expect(fn () => new ConnectorLifecycleHealth(
        workspaceId: $fixture['workspace_id'],
        providerKey: $fixture['provider_key'],
        status: 'healthy',
        reason: 'contract_compatible',
        observedAt: $at,
        compatibilityEvidenceSha256: $assessment->evidenceSha256,
        deprecationEvidenceSha256: null,
        decisionAuditSha256: null,
        failureCode: null,
        reconciliationKey: hash('sha256', 'cross-tenant'),
        compatibilityEvidence: $assessment->toArray(),
    ))->toThrow(InvalidArgumentException::class);
});

it('records lifecycle health idempotently and verifies the evidence hash on read', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $health = task0086LifecycleHealth($fixture['workspace_id'], $fixture['provider_key'], '2026-10-08T00:00:00+00:00', 'operation-a');
    $repository = app(ConnectorLifecycleHealthRepository::class);

    $first = $repository->record($health);
    $repeat = $repository->record($health);

    expect($first->evidenceSha256)->toBe($health->evidenceSha256)
        ->and($repeat->toArray())->toBe($health->toArray())
        ->and($repository->findByReconciliationKey($fixture['workspace_id'], $fixture['provider_key'], $health->reconciliationKey)?->evidenceSha256)
        ->toBe($health->evidenceSha256)
        ->and(DB::table('connector_lifecycle_health')->count())->toBe(1);
});

it('rejects idempotency-key evidence conflicts and keeps reads workspace scoped', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $repository = app(ConnectorLifecycleHealthRepository::class);
    $first = task0086LifecycleHealth($fixture['workspace_id'], $fixture['provider_key'], '2026-10-08T00:00:00+00:00', 'same-key');
    $second = task0086LifecycleHealth($fixture['workspace_id'], $fixture['provider_key'], '2026-10-08T00:01:00+00:00', 'same-key');

    $repository->record($first);
    expect(fn () => $repository->record($second))->toThrow(InvalidArgumentException::class)
        ->and($repository->findLatestForProvider($fixture['workspace_id'], $fixture['provider_key'])?->evidenceSha256)
        ->toBe($first->evidenceSha256);

    $other = task0086LifecyclePersistenceFixture();
    expect($repository->findByReconciliationKey($other['workspace_id'], $fixture['provider_key'], $first->reconciliationKey))->toBeNull()
        ->and($repository->findLatestForProvider($other['workspace_id'], $fixture['provider_key']))->toBeNull();
});

it('retains dated deprecation provenance and compatibility snapshots for operator review', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
    $assessment = ConnectorCompatibilityAssessment::assess(
        $fixture['workspace_id'],
        $fixture['provider_key'],
        '1.2.0',
        '1.2.0',
        ['contacts.read' => '1.0.0'],
        ['contacts.read' => '1.0.0'],
        $at,
    );
    $deprecation = new ConnectorDeprecationObservation(
        $fixture['workspace_id'],
        $fixture['provider_key'],
        '1.2.0',
        'https://docs.example.test/changelog',
        hash('sha256', 'dated-source'),
        $at,
        new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
    );
    $health = (new ConnectorLifecycleReconciler)->reconcile($assessment, $deprecation, null, $at);
    $repository = app(ConnectorLifecycleHealthRepository::class);
    $persisted = $repository->record($health);

    expect($persisted->status)->toBe('degraded')
        ->and($persisted->compatibilityEvidence['baseline_contract_version'])->toBe('1.2.0')
        ->and($persisted->deprecationEvidence['source_uri'])->toBe('https://docs.example.test/changelog')
        ->and($persisted->deprecationEvidence['deprecated_at'])->toBe('2026-10-01T00:00:00+00:00')
        ->and($persisted->deprecationEvidence['automatic_upgrade'])->toBeFalse()
        ->and($repository->findLatestForProvider($fixture['workspace_id'], $fixture['provider_key'])?->evidenceSha256)
        ->toBe($health->evidenceSha256);
});

it('retains auditable operator decision evidence with lifecycle health', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
    $assessment = ConnectorCompatibilityAssessment::assess(
        $fixture['workspace_id'],
        $fixture['provider_key'],
        '1.2.0',
        '1.2.0',
        ['contacts.read' => '1.0.0'],
        ['contacts.read' => '1.0.0'],
        $at,
    );
    $decision = new ConnectorLifecycleDecision(
        $fixture['workspace_id'],
        $fixture['provider_key'],
        'rollback',
        'operator_requested_recovery',
        'operator-1',
        hash('sha256', 'rollback-operation'),
        $at,
        $assessment->evidenceSha256,
        hash('sha256', 'approved-candidate'),
    );
    $health = (new ConnectorLifecycleReconciler)->reconcile($assessment, null, $decision, $at);
    $persisted = app(ConnectorLifecycleHealthRepository::class)->record($health);

    expect($persisted->status)->toBe('rollback_pending')
        ->and($persisted->decisionEvidence['action'])->toBe('rollback')
        ->and($persisted->decisionEvidence['actor_id'])->toBe('operator-1')
        ->and($persisted->decisionEvidence['rollback_candidate_id'])->toBe(hash('sha256', 'approved-candidate'))
        ->and($persisted->decisionEvidence['compatibility_evidence_sha256'])->toBe($assessment->evidenceSha256);
});

it('refuses to drop non-empty lifecycle evidence during migration rollback', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $health = task0086LifecycleHealth($fixture['workspace_id'], $fixture['provider_key'], '2026-10-08T00:00:00+00:00', 'preserve');
    app(ConnectorLifecycleHealthRepository::class)->record($health);
    $migration = require database_path('migrations/2026_10_08_000016_create_connector_lifecycle_health_evidence_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('connector_lifecycle_health')->count())->toBe(1);
});

it('rejects an unknown partial schema rather than adopting it', function () {
    $migration = require database_path('migrations/2026_10_08_000016_create_connector_lifecycle_health_evidence_table.php');
    $migration->down();
    Schema::create('connector_lifecycle_health', fn ($table) => $table->uuid('id')->primary());

    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('connector_lifecycle_health', 'workspace_id'))->toBeFalse();

    Schema::drop('connector_lifecycle_health');
    $migration->up();
});

it('fails closed when a persisted lifecycle evidence snapshot is tampered with', function () {
    $fixture = task0086LifecyclePersistenceFixture();
    $health = task0086LifecycleHealth($fixture['workspace_id'], $fixture['provider_key'], '2026-10-08T00:00:00+00:00', 'tamper-check');
    $repository = app(ConnectorLifecycleHealthRepository::class);
    $repository->record($health);

    $snapshot = json_decode((string) DB::table('connector_lifecycle_health')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('provider_key', $fixture['provider_key'])
        ->where('reconciliation_key', $health->reconciliationKey)
        ->value('compatibility_evidence'), true, 512, JSON_THROW_ON_ERROR);
    $snapshot['reason'] = 'tampered_after_persistence';
    DB::table('connector_lifecycle_health')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('provider_key', $fixture['provider_key'])
        ->where('reconciliation_key', $health->reconciliationKey)
        ->update(['compatibility_evidence' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

    expect(fn () => $repository->findByReconciliationKey(
        $fixture['workspace_id'],
        $fixture['provider_key'],
        $health->reconciliationKey,
    ))->toThrow(RuntimeException::class, 'integrity check');
});
