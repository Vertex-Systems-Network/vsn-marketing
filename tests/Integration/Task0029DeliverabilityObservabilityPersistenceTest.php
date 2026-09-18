<?php

use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;
use App\Modules\DeliveryEngine\Infrastructure\Deliverability\DatabaseDeliverabilityObservationRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0029 PostgreSQL certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0029 PostgreSQL certification requires the pgsql driver.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

function task0029PersistenceWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0029 '.$suffix,
        'slug' => 'task0029-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0029 Workspace '.$suffix,
        'slug' => 'task0029-workspace-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0029PersistenceRepository(): DatabaseDeliverabilityObservationRepository
{
    return new DatabaseDeliverabilityObservationRepository(app(DatabaseManager::class));
}

function task0029PersistenceObservation(
    string $workspaceId,
    string $id,
    string $replayKey,
    string $signalValue = '0.001',
    string $providerKey = 'provider-a',
    string $messagePurpose = 'marketing',
    DeliverabilitySignalKind $kind = DeliverabilitySignalKind::Complaint,
    string $signalKey = 'complaint_rate',
    string $observedAt = '2026-09-18T10:00:00+00:00',
    string $recordedAt = '2026-09-18T10:01:00+00:00',
    ?string $freshUntil = '2026-09-18T12:00:00+00:00',
    bool $trusted = true,
): DeliverabilityObservation {
    return new DeliverabilityObservation(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: $providerKey,
        source: 'provider-feedback-feed',
        version: 'provider-a-2026-09-v1',
        messagePurpose: $messagePurpose,
        kind: $kind,
        signalKey: $signalKey,
        signalValue: $signalValue,
        provenanceReference: 'provider-doc://provider-a/feedback/2026-09',
        replayKey: $replayKey,
        effectiveAt: new DateTimeImmutable('2026-09-18T09:00:00+00:00'),
        observedAt: new DateTimeImmutable($observedAt),
        recordedAt: new DateTimeImmutable($recordedAt),
        freshUntil: $freshUntil === null ? null : new DateTimeImmutable($freshUntil),
        trusted: $trusted,
    );
}

it('persists provider-versioned observations durably with deterministic scoped ordering', function () {
    $workspaceId = task0029PersistenceWorkspace('round-trip');
    $repository = task0029PersistenceRepository();

    $later = task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'observation-later',
        replayKey: 'replay-later',
        signalValue: '0.002',
        observedAt: '2026-09-18T10:20:00+00:00',
        recordedAt: '2026-09-18T10:21:00+00:00',
    );
    $earlier = task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'observation-earlier',
        replayKey: 'replay-earlier',
        signalValue: '0.001',
        observedAt: '2026-09-18T10:10:00+00:00',
        recordedAt: '2026-09-18T10:11:00+00:00',
    );
    $otherProvider = task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'observation-other-provider',
        replayKey: 'replay-other-provider',
        providerKey: 'provider-b',
        signalValue: '0.003',
    );

    $repository->append($later);
    $repository->append($earlier);
    $repository->append($otherProvider);

    $freshRepository = task0029PersistenceRepository();
    $scoped = $freshRepository->observations($workspaceId, 'provider-a', 'marketing');

    expect($scoped)->toHaveCount(2)
        ->and(array_map(fn (DeliverabilityObservation $item): string => $item->id, $scoped))
        ->toBe(['observation-earlier', 'observation-later'])
        ->and($scoped[0]->workspaceId)->toBe($workspaceId)
        ->and($scoped[0]->providerKey)->toBe('provider-a')
        ->and($scoped[0]->source)->toBe('provider-feedback-feed')
        ->and($scoped[0]->version)->toBe('provider-a-2026-09-v1')
        ->and($scoped[0]->messagePurpose)->toBe('marketing')
        ->and($scoped[0]->kind)->toBe(DeliverabilitySignalKind::Complaint)
        ->and($scoped[0]->signalKey)->toBe('complaint_rate')
        ->and($scoped[0]->signalValue)->toBe('0.001')
        ->and($scoped[0]->provenanceReference)->toBe('provider-doc://provider-a/feedback/2026-09')
        ->and($scoped[0]->replayKey)->toBe('replay-earlier')
        ->and($scoped[0]->trusted)->toBeTrue();
});

it('makes exact replay idempotent and rejects conflicting replay evidence', function () {
    $workspaceId = task0029PersistenceWorkspace('replay');
    $repository = task0029PersistenceRepository();
    $observation = task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'replay-observation',
        replayKey: 'stable-replay',
    );

    $first = $repository->append($observation);
    $second = $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'replay-observation',
        replayKey: 'stable-replay',
    ));

    expect($second->id)->toBe($first->id)
        ->and(DB::table('deliverability_observations')->where('workspace_id', $workspaceId)->count())->toBe(1);

    expect(fn () => $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'replay-observation',
        replayKey: 'stable-replay',
        signalValue: '0.999',
    )))->toThrow(InvalidArgumentException::class, 'replay key conflicts with different evidence');

    expect(DB::table('deliverability_observations')->where('workspace_id', $workspaceId)->count())->toBe(1);
});

it('fails closed on cross-workspace observation identity reuse and keeps reads tenant isolated', function () {
    $workspaceA = task0029PersistenceWorkspace('tenant-a');
    $workspaceB = task0029PersistenceWorkspace('tenant-b');
    $repository = task0029PersistenceRepository();

    $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceA,
        id: 'shared-observation-id',
        replayKey: 'tenant-a-replay',
    ));

    expect(fn () => $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceB,
        id: 'shared-observation-id',
        replayKey: 'tenant-b-replay',
    )))->toThrow(AuthorizationException::class, 'Deliverability observation access denied');

    expect($repository->observations($workspaceB))->toBe([])
        ->and($repository->observations($workspaceA))->toHaveCount(1);
});

it('rejects same-workspace id reuse under a different replay identity', function () {
    $workspaceId = task0029PersistenceWorkspace('id-reuse');
    $repository = task0029PersistenceRepository();

    $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'immutable-id',
        replayKey: 'first-replay',
    ));

    expect(fn () => $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'immutable-id',
        replayKey: 'different-replay',
    )))->toThrow(
        InvalidArgumentException::class,
        'Deliverability observation ID already exists with a different replay identity',
    );
});

it('preserves stale and untrusted evidence explicitly instead of upgrading it', function () {
    $workspaceId = task0029PersistenceWorkspace('uncertain');
    $repository = task0029PersistenceRepository();

    $repository->append(task0029PersistenceObservation(
        workspaceId: $workspaceId,
        id: 'uncertain-observation',
        replayKey: 'uncertain-replay',
        observedAt: '2026-09-18T10:00:00+00:00',
        recordedAt: '2026-09-18T10:01:00+00:00',
        freshUntil: '2026-09-18T10:30:00+00:00',
        trusted: false,
    ));

    $stored = $repository->observations($workspaceId)[0];

    expect($stored->trusted)->toBeFalse()
        ->and($stored->freshUntil)->toEqual(new DateTimeImmutable('2026-09-18T10:30:00+00:00'))
        ->and($stored->isStaleAt(new DateTimeImmutable('2026-09-18T11:00:00+00:00')))->toBeTrue();
});
