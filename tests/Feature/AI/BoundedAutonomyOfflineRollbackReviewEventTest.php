<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRollbackReviewEvent;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyRollbackOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offlineRollbackLedgerScope(): TenantContext
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Offline rollback fixture',
        'slug' => 'rollback-review-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org,
        'name' => 'Rollback review workspace',
        'slug' => 'rollback-review-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return new TenantContext($org, $workspace, null, 'operator');
}

function offlineRollbackLedgerAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T12:00:00+00:00');
}

function offlineRollbackLedgerFacts(TenantContext $actor): array
{
    return [
        'tenant' => $actor->toArray(),
        'run_id' => 'run-1',
        'snapshot_sha256' => str_repeat('a', 64),
        'source_sha256' => str_repeat('b', 64),
        'observed_at_unix' => offlineRollbackLedgerAt()->getTimestamp(),
        'attempted_at_unix' => offlineRollbackLedgerAt()->getTimestamp() - 600,
        'callback_count' => 1,
        'outcome' => 'confirmed_not_applied',
        'outcome_verified_independently' => true,
        'late_callback' => false,
        'duplicate_callback' => false,
        'external_cost_minor' => 0,
    ];
}

function offlineRollbackLedgerService(?object $facts, object $access): DatabaseBoundedAutonomyOfflineRollbackReviewEvent
{
    $source = $facts === null ? new DenyingBoundedAutonomyRollbackOutcomeSource
        : new class($facts) implements BoundedAutonomyRollbackOutcomeSource
        {
            public function __construct(private object $facts) {}

            public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        };
    $permission = new class($access) implements ExperimentAccess
    {
        public function __construct(private object $access) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->access->allowed && $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new DatabaseBoundedAutonomyOfflineRollbackReviewEvent(
        new BoundedAutonomyOfflineRollbackReview($source), $permission,
    );
}

it('records unknown evidence exactly once and conservatively preserves later irreversible and late states', function () {
    $actor = offlineRollbackLedgerScope();
    $access = (object) ['allowed' => true];
    $service = offlineRollbackLedgerService(null, $access);
    $digest = str_repeat('a', 64);
    $one = $service->record($actor, 'run-1', $digest, offlineRollbackLedgerAt());
    expect($one['status'])->toBe('held_offline')
        ->and($one['reason_code'])->toBe('provider_outcome_unknown')
        ->and($one['rollback_performed'])->toBeFalse();

    $repeat = $service->record($actor, 'run-1', $digest, offlineRollbackLedgerAt());
    expect($repeat['id'])->toBe($one['id'])
        ->and($repeat['sequence'])->toBe(1)
        ->and($repeat['replayed'])->toBeTrue();

    $facts = (object) ['value' => array_replace(offlineRollbackLedgerFacts($actor), [
        'outcome' => 'confirmed_applied',
    ])];
    $verified = offlineRollbackLedgerService($facts, $access);
    $second = $verified->record($actor, 'run-1', $digest, offlineRollbackLedgerAt());
    expect($second['status'])->toBe('held_offline')
        ->and($second['reason_code'])->toBe('irreversible_external_effect_requires_manual_recovery')
        ->and($second['sequence'])->toBe(2);

    $facts->value = offlineRollbackLedgerFacts($actor);
    $third = $verified->record($actor, 'run-1', $digest, offlineRollbackLedgerAt());
    expect($third['status'])->toBe('held_offline')
        ->and($third['reason_code'])->toBe('prior_uncertain_outcome_requires_human_reconciliation')
        ->and($third['rollback_performed'])->toBeFalse()
        ->and($third['refund_authorized'])->toBeFalse()
        ->and($third['retry_authorized'])->toBeFalse()
        ->and($third['execution_authorized'])->toBeFalse()
        ->and($third['promotion_authorized'])->toBeFalse();

    $repeatLast = $verified->record($actor, 'run-1', $digest, offlineRollbackLedgerAt());
    expect($repeatLast['id'])->toBe($third['id'])
        ->and($repeatLast['replayed'])->toBeTrue()
        ->and(DB::table('ai_autonomy_offline_rollback_events')->where('workspace_id', $actor->workspaceId)->count())->toBe(3);
});

it('allows an initial independently verified non-effect only as an offline review, not a refund', function () {
    $actor = offlineRollbackLedgerScope();
    $facts = (object) ['value' => offlineRollbackLedgerFacts($actor)];
    $service = offlineRollbackLedgerService($facts, (object) ['allowed' => true]);
    $review = $service->record($actor, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt());
    expect($review['status'])->toBe('offline_rollback_review_ready')
        ->and($review['rollback_performed'])->toBeFalse()
        ->and($review['refund_authorized'])->toBeFalse()
        ->and($review['execution_authorized'])->toBeFalse();
});

it('rechecks read permission, actor, org, fingerprint and rejects forged provider success with no writes', function () {
    $actor = offlineRollbackLedgerScope();
    $access = (object) ['allowed' => true];
    $facts = (object) ['value' => offlineRollbackLedgerFacts($actor)];
    $service = offlineRollbackLedgerService($facts, $access);

    $access->allowed = false;
    expect(fn () => $service->record($actor, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt()))
        ->toThrow(InvalidArgumentException::class);
    $access->allowed = true;

    $foreign = new TenantContext((string) Str::uuid(), $actor->workspaceId, null, 'operator');
    expect(fn () => $service->record($foreign, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt()))
        ->toThrow(InvalidArgumentException::class);
    $forged = $facts->value;
    $forged['rollback_performed'] = true;
    $facts->value = $forged;
    expect(fn () => $service->record($actor, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt()))
        ->toThrow(InvalidArgumentException::class);
    $facts->value = offlineRollbackLedgerFacts($actor);

    $first = $service->record($actor, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt());
    $imposter = new TenantContext($actor->organizationId, $actor->workspaceId, null, 'other-actor');
    $facts->value['tenant'] = $imposter->toArray();
    expect(fn () => $service->record($imposter, 'run-1', str_repeat('a', 64), offlineRollbackLedgerAt()))
        ->toThrow(InvalidArgumentException::class);
    expect($first['status'])->toBe('offline_rollback_review_ready')
        ->and(DB::table('ai_autonomy_offline_rollback_events')->count())->toBe(1);
});
