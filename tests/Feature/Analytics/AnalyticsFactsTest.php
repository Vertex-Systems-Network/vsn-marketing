<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function analyticsReport(AnalyticsFacts $service, AnalyticsFixture $fixture, string $unit = 'event'): array
{
    return $service->snapshot($fixture->actor, new MetricDefinition('product.viewed', $unit),
        new DateTimeImmutable('2026-10-02T00:00:00Z'), new DateTimeImmutable('2026-10-03T00:00:00Z'), $fixture->now());
}

it('admits canonical facts once and keeps PII out of the derived tables', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $id = $f->event('one');
    expect($s->project($f->actor, $id))->toBe('admitted')->and($s->project($f->actor, $id))->toBe('replayed');
    $s->project($f->actor, $f->event('two'));
    $report = analyticsReport($s, $f);
    expect($report['value'])->toBe(2)->and($report['unique_subjects'])->toBe(1)
        ->and(analyticsReport($s, $f, 'subject')['value'])->toBe(1)
        ->and($report['source_completeness'])->toBe('unknown')->and($report['publication_authorized'])->toBeFalse()
        ->and($s->readSnapshot($f->actor, $report['id']))->toBe($report)
        ->and(json_encode(DB::table('analytics_facts')->get()))->not->toContain('Private name', $f->contact)
        ->and(json_encode(DB::table('analytics_snapshots')->get()))->not->toContain('Private name', $f->contact);
});

it('quarantines source collisions without overwriting the admitted event', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $first = $f->event('same-source');
    $s->project($f->actor, $first);
    $prior = analyticsReport($s, $f);
    expect($s->project($f->actor, $f->event('same-source')))->toBe('conflict')
        ->and(DB::table('analytics_facts')->count())->toBe(1)
        ->and(DB::table('analytics_facts')->value('event_id'))->toBe($first)
        ->and(analyticsReport($s, $f)['value'])->toBe(0)
        ->and(fn () => $s->readSnapshot($f->actor, $prior['id']))->toThrow(RuntimeException::class);
});

it('denies missing approval, consent, future grant and revoked purpose', function () {
    $f = new AnalyticsFixture(false);
    $s = app(AnalyticsFacts::class);
    $id = $f->event();
    expect(fn () => $s->project($f->actor, $id))->toThrow(InvalidArgumentException::class);
    $f->consent(ConsentDecision::Granted, '2026-10-03T11:00:00Z');
    expect(fn () => $s->project($f->actor, $id))->toThrow(InvalidArgumentException::class);
    config(['analytics.purpose_approved' => false]);
    expect(fn () => $s->project($f->actor, $id))->toThrow(AuthorizationException::class);
    config(['analytics.purpose_approved' => true, 'analytics.retention_days' => '30']);
    expect(fn () => $s->project($f->actor, $id))->toThrow(AuthorizationException::class);
});

it('rechecks revocation and expiry before returning stored reports', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event());
    $report = analyticsReport($s, $f);
    $f->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    expect(fn () => $s->readSnapshot($f->actor, $report['id']))->toThrow(RuntimeException::class)
        ->and(analyticsReport($s, $f)['value'])->toBe(0);
});

it('enforces real RBAC and organization workspace brand boundaries', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $id = $f->event();
    $other = new AnalyticsFixture;
    expect(fn () => $s->project($other->actor, $id))->toThrow(AuthorizationException::class)
        ->and(fn () => $s->project(new TenantContext($other->actor->organizationId, $f->actor->workspaceId, $f->actor->brandId, $f->actor->actorId), $id))
        ->toThrow(AuthorizationException::class);
    DB::table('workspace_role_permissions')->where('workspace_role_id', $f->role)->where('permission', 'analytics.read')->delete();
    expect(fn () => $s->project($f->actor, $id))->toThrow(AuthorizationException::class);
});

it('invalidates derived facts and reports while preserving canonical events', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $id = $f->event();
    $s->project($f->actor, $id);
    $report = analyticsReport($s, $f);
    $wide = new TenantContext($f->actor->organizationId, $f->actor->workspaceId, null, $f->actor->actorId);
    $s->invalidateSubject($wide, $f->contact);
    $s->invalidateSubject($wide, $f->contact);
    expect(DB::table('analytics_facts')->count())->toBe(0)->and(DB::table('analytics_snapshots')->count())->toBe(0)
        ->and(DB::table('customer_events')->where('id', $id)->exists())->toBeTrue()
        ->and(fn () => $s->project($f->actor, $id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->readSnapshot($f->actor, $report['id']))->toThrow(AuthorizationException::class);
});

it('rejects corrupted canonical envelopes and stored reports', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $id = $f->event();
    $s->project($f->actor, $id);
    $report = analyticsReport($s, $f);
    DB::table('customer_events')->where('id', $id)->update(['payload' => '{"changed":true}']);
    expect(fn () => $s->readSnapshot($f->actor, $report['id']))->toThrow(RuntimeException::class)
        ->and(analyticsReport($s, $f)['value'])->toBe(0);
    DB::table('analytics_snapshots')->where('id', $report['id'])->update(['report' => '{"value":999}']);
    expect(fn () => $s->readSnapshot($f->actor, $report['id']))->toThrow(RuntimeException::class);
});

it('uses half open windows and immutable receipt cutoffs for late arrivals', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('boundary', '2026-10-03T00:00:00Z', '2026-10-03T00:00:00Z'));
    $s->project($f->actor, $f->event('included'));
    $prior = analyticsReport($s, $f);
    $f->time = $f->time->modify('+1 hour');
    $s->project($f->actor, $f->event('late', '2026-10-02T11:00:00Z', '2026-10-03T12:30:00Z'));
    expect($prior['value'])->toBe(1)->and($s->readSnapshot($f->actor, $prior['id']))->toBe($prior)
        ->and(analyticsReport($s, $f)['value'])->toBe(2);
});

it('denies future envelopes and invalid windows instead of guessing', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    expect(fn () => $s->project($f->actor, $f->event('future', '2026-10-04T00:00:00Z', '2026-10-04T00:00:00Z')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->snapshot($f->actor, new MetricDefinition('product.viewed'), new DateTimeImmutable('2026-10-03Z'), new DateTimeImmutable('2026-10-02Z'), $f->now()))->toThrow(InvalidArgumentException::class);
});

it('invalidates stored reports after retention expires or the pseudonym key changes', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event());
    $report = analyticsReport($s, $f);
    config(['app.key' => str_repeat('different-key', 4)]);
    expect(fn () => app(AnalyticsFacts::class)->readSnapshot($f->actor, $report['id']))->toThrow(RuntimeException::class);
    $f->time = new DateTimeImmutable('2026-11-02T12:00:00Z');
    expect(fn () => $s->readSnapshot($f->actor, $report['id']))->toThrow(RuntimeException::class);
});

it('rejects cardinality overflow without returning a truncated total', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    for ($i = 0; $i <= AnalyticsFacts::MAX_FACTS; $i++) {
        $s->project($f->actor, $f->event('bounded-'.$i));
    }
    expect(fn () => analyticsReport($s, $f))->toThrow(RuntimeException::class)
        ->and(DB::table('analytics_snapshots')->count())->toBe(0);
});
