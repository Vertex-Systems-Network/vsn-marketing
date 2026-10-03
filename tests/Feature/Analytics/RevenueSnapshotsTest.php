<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\RevenueDefinition;
use App\Modules\Analytics\Domain\RevenueExperimentVerifier;
use App\Modules\Analytics\Infrastructure\CanonicalRevenueExperimentVerifier;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Experiments\Infrastructure\WorkspaceExperimentAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function revenueReport(AnalyticsFacts $s, AnalyticsFixture $f, ?RevenueDefinition $d = null): array
{
    return $s->revenueSnapshot($f->actor, $d ?? new RevenueDefinition, new DateTimeImmutable('2026-10-02Z'),
        new DateTimeImmutable('2026-10-03Z'), $f->now());
}

function revenueEvent(AnalyticsFacts $s, AnalyticsFixture $f, string $source, string $type, array $payload,
    string $at = '2026-10-02T10:00:00Z', string $received = '2026-10-02T11:00:00Z'): string
{
    $id = $f->event($source, $at, $received, $type, $payload);
    $s->project($f->actor, $id);

    return $id;
}

function revenuePayload(string $id = 'purchase_1', int $amount = 101, string $currency = 'USD'): array
{
    return ['transaction_id' => $id, 'amount_minor' => $amount, 'currency' => $currency,
        'currency_exponent' => $currency === 'JPY' ? 0 : 2];
}

it('conserves net revenue by currency and excludes equal-time or unsupported touches', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'first', 'product.viewed', ['channel' => 'email'], '2026-10-02T08:00:00Z');
    revenueEvent($s, $f, 'last', 'message.clicked', ['channel' => 'sms'], '2026-10-02T09:00:00Z');
    revenueEvent($s, $f, 'tie', 'product.viewed', ['channel' => 'push']);
    revenueEvent($s, $f, 'purchase', 'order.completed', revenuePayload());
    revenueEvent($s, $f, 'refund', 'order.refunded', [...revenuePayload(amount: 31), 'refund_id' => 'refund_1'], '2026-10-02T10:30:00Z');
    revenueEvent($s, $f, 'jpy', 'order.completed', revenuePayload('purchase_jpy', 77, 'JPY'));
    $r = revenueReport($s, $f);
    expect($r['result']['currencies']['USD'])->toBe(['exponent' => 2, 'gross' => 101, 'refunded' => 31, 'net' => 70, 'purchases' => 1])
        ->and($r['result']['attribution_credit_minor_units'])->toBe(['JPY' => ['sms' => 77], 'USD' => ['sms' => 70]])
        ->and($s->readSnapshot($f->actor, $r['id']))->toBe($r)
        ->and(json_encode($r))->not->toContain('purchase_1', $f->contact, 'Private name');
    expect(revenueReport($s, $f, new RevenueDefinition('first_touch'))['result']['attribution_credit_minor_units']['USD'])->toBe(['email' => 70]);
});

it('deduplicates semantic money identities and quarantines conflicting values across retained history', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'one', 'order.completed', revenuePayload());
    revenueEvent($s, $f, 'same', 'order.completed', revenuePayload());
    $r = revenueReport($s, $f);
    expect($r['result']['currencies']['USD']['gross'])->toBe(101)
        ->and($r['result']['quality']['duplicate_money'])->toBe(1)
        ->and($r['result']['attribution_credit_minor_units']['USD'])->toBe(['unattributed' => 101]);
    revenueEvent($s, $f, 'conflict', 'order.completed', revenuePayload(amount: 102));
    $conflict = revenueReport($s, $f);
    expect($conflict['result']['currencies'])->toBe([])
        ->and($conflict['result']['quality']['conflicting_identities'])->toBe(1)
        ->and($s->readSnapshot($f->actor, $r['id']))->toBe($r);
});

it('reconciles an out-of-arrival-order refund without rewriting an earlier unresolved snapshot', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'refund-first', 'order.refunded', [...revenuePayload(amount: 20), 'refund_id' => 'r1'], '2026-10-02T10:30:00Z');
    $prior = revenueReport($s, $f);
    expect($prior['result']['quality']['unresolved_refunds'])->toBe(1);
    $f->time = $f->time->modify('+1 hour');
    revenueEvent($s, $f, 'purchase-late', 'order.completed', revenuePayload(), received: '2026-10-03T12:30:00Z');
    revenueEvent($s, $f, 'refund-replay', 'order.refunded', [...revenuePayload(amount: 20), 'refund_id' => 'r1'], '2026-10-02T10:30:00Z', '2026-10-03T12:30:00Z');
    $next = revenueReport($s, $f);
    expect($next['result']['currencies']['USD']['net'])->toBe(81)
        ->and($next['result']['quality']['unresolved_refunds'])->toBe(0)
        ->and($next['result']['quality']['duplicate_money'])->toBe(1)
        ->and($s->readSnapshot($f->actor, $prior['id']))->toBe($prior);
});

it('rejects over-refunds, wrong currencies and malformed minor unit payloads', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'purchase', 'order.completed', revenuePayload());
    revenueEvent($s, $f, 'over', 'order.refunded', [...revenuePayload(amount: 102), 'refund_id' => 'over'], '2026-10-02T10:30:00Z');
    revenueEvent($s, $f, 'wrong', 'order.refunded', [...revenuePayload(currency: 'JPY'), 'refund_id' => 'wrong'], '2026-10-02T10:30:00Z');
    revenueEvent($s, $f, 'float', 'order.completed', [...revenuePayload('bad'), 'amount_minor' => 1.25]);
    revenueEvent($s, $f, 'exponent', 'order.completed', [...revenuePayload('bad2'), 'currency_exponent' => 0]);
    $r = revenueReport($s, $f);
    expect($r['result']['quality']['rejected_refunds'])->toBe(2)
        ->and($r['result']['quality']['invalid_money'])->toBe(2)
        ->and($r['result']['currencies']['USD']['net'])->toBe(101);
});

it('returns exact observed mature cohort LTV and censors incomplete subjects', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'cohort', 'contact.created', [], '2026-10-02T00:00:00Z');
    revenueEvent($s, $f, 'purchase', 'order.completed', revenuePayload());
    $r = revenueReport($s, $f);
    expect($r['result']['cohort_size'])->toBe(1)
        ->and($r['result']['observed_ltv']['USD']['mean_minor_units'])->toBe(['numerator' => 101, 'denominator' => 1])
        ->and($r['result']['lifetime_prediction'])->toBeFalse()
        ->and(revenueReport($s, $f, new RevenueDefinition(ltvHorizonSeconds: 2 * 86400))['result']['observed_ltv']['USD']['mean_minor_units'])->toBeNull();
});

it('denies foreign and revoked-purpose revenue reads and ignores forged experiment identifiers', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'purchase', 'order.completed', [...revenuePayload(), 'experiment_exposure_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']);
    $r = revenueReport($s, $f);
    expect(array_values($r['experiment_lineage']))->toBe([null])
        ->and($r['result']['causal_incrementality'])->toBeFalse();
    $other = new AnalyticsFixture;
    expect(fn () => $s->readSnapshot($other->actor, $r['id']))->toThrow(AuthorizationException::class);
    $f->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    expect(fn () => $s->readSnapshot($f->actor, $r['id']))->toThrow(RuntimeException::class)
        ->and(revenueReport($s, $f)['result']['currencies'])->toBe([]);
});

it('binds witnessed experiment assignment to the exact canonical contact and invalidates revoked evidence', function () {
    $f = new AnalyticsFixture;
    app(WorkspaceRoleManager::class)
        ->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $allocator = new ExperimentAllocator(str_repeat('e', 32));
    $plan = new ExperimentPlan((string) Str::uuid(),
        $f->actor->workspaceId, $f->actor->brandId, 'revenue-test', 'contact', ['control' => 5000, 'variant' => 5000], 'control', null);
    $assignment = (string) Str::uuid();
    $exposure = (string) Str::uuid();
    $variant = $allocator->variant($plan, $f->contact);
    DB::table('experiments')->insert([
        'id' => $plan->id, 'workspace_id' => $plan->workspaceId, 'brand_id' => $plan->brandId,
        'layer' => $plan->layer, 'unit_kind' => 'contact', 'control_variant' => 'control',
        'allocation' => json_encode($plan->weights), 'plan_hash' => $plan->fingerprint(),
        'key_fingerprint' => $allocator->keyFingerprint(), 'status' => 'active', 'created_by_actor_id' => 'fixture',
    ]);
    DB::table('experiment_assignments')->insert([
        'id' => $assignment, 'workspace_id' => $plan->workspaceId, 'experiment_id' => $plan->id,
        'layer' => $plan->layer, 'subject_key' => $allocator->subjectKey($plan, $f->contact),
        'variant' => $variant, 'plan_hash' => $plan->fingerprint(), 'assigned_at' => '2026-10-02T08:00:00Z',
    ]);
    DB::table('experiment_exposures')->insert([
        'id' => $exposure, 'workspace_id' => $plan->workspaceId, 'assignment_id' => $assignment,
        'variant' => $variant, 'treatment_reference' => 'test-independent-receipt', 'exposed_at' => '2026-10-02T09:00:00Z',
    ]);
    $witness = new class implements ExposureVerifier
    {
        public bool $valid = true;

        public function witnessed(TenantContext $actor, string $experimentId,
            string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): bool
        {
            return $this->valid && $reference === 'test-independent-receipt' && $at == new DateTimeImmutable('2026-10-02T09:00:00Z');
        }
    };
    $access = new WorkspaceExperimentAccess(
        app(WorkspaceAuthorizer::class),
        User::query()->findOrFail($f->actor->actorId));
    $verifier = new CanonicalRevenueExperimentVerifier($allocator, $access, $witness);
    app()->instance(RevenueExperimentVerifier::class, $verifier);
    $s = app(AnalyticsFacts::class);
    revenueEvent($s, $f, 'purchase', 'order.completed', [...revenuePayload(), 'experiment_exposure_id' => $exposure]);
    $r = revenueReport($s, $f);
    expect(array_values($r['experiment_lineage'])[0]['exposure_id'])->toBe($exposure)
        ->and(array_values($r['experiment_lineage'])[0]['causal_result'])->toBeFalse()
        ->and($verifier->reference($f->actor, 'foreign-contact', $exposure, new DateTimeImmutable('2026-10-02T10:00:00Z')))->toBeNull()
        ->and($verifier->reference($f->actor, $f->contact, $exposure, new DateTimeImmutable('2026-10-02T09:00:00Z')))->toBeNull();
    $witness->valid = false;
    expect(fn () => $s->readSnapshot($f->actor, $r['id']))->toThrow(RuntimeException::class);
});
