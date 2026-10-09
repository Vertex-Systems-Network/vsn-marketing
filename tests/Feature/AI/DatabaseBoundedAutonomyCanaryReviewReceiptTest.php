<?php

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyCanaryReviewReceipt;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryCohortSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function canaryReceiptFixture(): array
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Canary evidence fixture',
        'slug' => 'canary-receipt-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Canary workspace',
        'slug' => 'canary-receipt-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $scope = new TenantContext($org, $workspace, null, 'operator');
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'canary_review', 'company',
        ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000], 'control', 'holdout');

    DB::table('experiments')->insert([
        'id' => $plan->id, 'workspace_id' => $workspace, 'brand_id' => null,
        'layer' => $plan->layer, 'unit_kind' => $plan->unitKind,
        'control_variant' => $plan->control, 'holdout_variant' => $plan->holdout,
        'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
        'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => str_repeat('a', 64),
        'status' => 'active', 'created_by_actor_id' => 'creator',
        'approved_by_actor_id' => 'human-owner', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$scope, $plan];
}

function canaryReceiptFacts(TenantContext $scope, ExperimentPlan $plan, DateTimeImmutable $at): array
{
    return [
        'tenant' => $scope->toArray(),
        'plan_sha256' => $plan->fingerprint(),
        'assignment_manifest_sha256' => str_repeat('b', 64),
        'observed_at_unix' => $at->getTimestamp(),
        'expires_at_unix' => $at->getTimestamp() + 300,
        'frozen' => true, 'consent_verified' => true,
        'quarantined' => 0, 'crossovers' => 0,
        'counts' => [
            'control' => ['eligible' => 450, 'assigned' => 450, 'exposed' => 410],
            'treatment' => ['eligible' => 450, 'assigned' => 450, 'exposed' => 400],
            'holdout' => ['eligible' => 100, 'assigned' => 100, 'exposed' => 0],
        ],
    ];
}

function canaryReceiptService(?object $facts, bool $canRead = true): DatabaseBoundedAutonomyCanaryReviewReceipt
{
    $source = $facts === null
        ? new DenyingBoundedAutonomyCanaryCohortSource
        : new class($facts) implements BoundedAutonomyCanaryCohortSource
        {
            public function __construct(private object $facts) {}

            public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        };
    $access = new class($canRead) implements ExperimentAccess
    {
        public function __construct(private bool $canRead) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->canRead && $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new DatabaseBoundedAutonomyCanaryReviewReceipt($source, $access);
}

it('does not persist missing evidence, inactive experiments or unauthorized operator claims', function () {
    [$actor, $plan] = canaryReceiptFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $held = canaryReceiptService(null)->record($actor, $plan, $at);
    expect($held['status'])->toBe('held_offline')
        ->and(DB::table('ai_autonomy_offline_canary_reviews')->count())->toBe(0);

    $facts = (object) ['value' => canaryReceiptFacts($actor, $plan, $at)];
    expect(fn () => canaryReceiptService($facts, false)->record($actor, $plan, $at))
        ->toThrow(InvalidArgumentException::class);
    DB::table('experiments')->where('id', $plan->id)->update(['status' => 'draft']);
    $stopped = canaryReceiptService($facts)->record($actor, $plan, $at);
    expect($stopped['reason_code'])->toBe('canonical_experiment_not_frozen_or_independently_approved')
        ->and(DB::table('ai_autonomy_offline_canary_reviews')->count())->toBe(0);
});

it('records one immutable independently witnessed offline cohort and denies altered replay', function () {
    [$actor, $plan] = canaryReceiptFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $facts = (object) ['value' => canaryReceiptFacts($actor, $plan, $at)];
    $service = canaryReceiptService($facts);
    $first = $service->record($actor, $plan, $at);
    expect($first['status'])->toBe('offline_cohort_review_ready')
        ->and($first['offline_receipt_recorded'])->toBeTrue()
        ->and($first['promotion_authorized'])->toBeFalse()
        ->and($service->record($actor, $plan, $at)['receipt_id'])->toBe($first['receipt_id'])
        ->and(DB::table('ai_autonomy_offline_canary_reviews')->count())->toBe(1);
    $facts->value['assignment_manifest_sha256'] = str_repeat('c', 64);
    expect(fn () => $service->record($actor, $plan, $at))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_offline_canary_reviews')->count())->toBe(1);
});

it('does not record foreign organization or revoked independent plan authority', function () {
    [$actor, $plan] = canaryReceiptFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $facts = (object) ['value' => canaryReceiptFacts($actor, $plan, $at)];
    $service = canaryReceiptService($facts);
    $foreign = new TenantContext((string) Str::uuid(), $actor->workspaceId, null, 'operator');
    expect(fn () => $service->record($foreign, $plan, $at))->toThrow(InvalidArgumentException::class);
    DB::table('experiments')->where('id', $plan->id)->update(['approved_by_actor_id' => 'creator']);
    $held = $service->record($actor, $plan, $at);
    expect($held['status'])->toBe('held_offline')
        ->and(DB::table('ai_autonomy_offline_canary_reviews')->count())->toBe(0);
});
