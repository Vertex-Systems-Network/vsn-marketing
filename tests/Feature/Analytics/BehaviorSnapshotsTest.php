<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function behaviorReport(AnalyticsFacts $service, AnalyticsFixture $fixture, BehaviorDefinition $definition): array
{
    return $service->behaviorSnapshot($fixture->actor, $definition, new DateTimeImmutable('2026-10-02Z'),
        new DateTimeImmutable('2026-10-03Z'), $fixture->now());
}

it('persists ordered behavior reports with exact canonical lineage and current privacy gates', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    foreach ([['product.viewed', '10:00'], ['cart.created', '10:30'], ['order.completed', '11:00']] as [$type, $time]) {
        $s->project($f->actor, $f->event($type, '2026-10-02T'.$time.':00Z', '2026-10-02T11:01:00Z', $type));
    }
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created', 'order.completed'], conversionSeconds: 3600);
    $r = behaviorReport($s, $f, $d);
    expect(array_column($r['result']['steps'], 'subjects'))->toBe([1, 1, 1])
        ->and($r['lineage'])->toHaveCount(3)->and($r['source_completeness'])->toBe('unknown')
        ->and($s->readSnapshot($f->actor, $r['id']))->toBe($r)
        ->and(json_encode($r))->not->toContain($f->contact, 'Private name');
    $other = new AnalyticsFixture;
    expect(fn () => $s->readSnapshot($other->actor, $r['id']))->toThrow(AuthorizationException::class);
    $f->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    expect(fn () => $s->readSnapshot($f->actor, $r['id']))->toThrow(RuntimeException::class)
        ->and(behaviorReport($s, $f, $d)['result']['entered_subjects'])->toBe(0);
});

it('keeps a previous funnel snapshot immutable when a late conversion arrives', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('entry'));
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created']);
    $prior = behaviorReport($s, $f, $d);
    $f->time = $f->time->modify('+1 hour');
    $s->project($f->actor, $f->event('late', '2026-10-02T10:30:00Z', '2026-10-03T12:30:00Z', 'cart.created'));
    expect($s->readSnapshot($f->actor, $prior['id']))->toBe($prior)
        ->and(array_column($prior['result']['steps'], 'subjects'))->toBe([1, 0])
        ->and(array_column(behaviorReport($s, $f, $d)['result']['steps'], 'subjects'))->toBe([1, 1]);
});

it('returns mature cohort denominators and censored incomplete retention bins', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('start', type: 'contact.created'));
    $s->project($f->actor, $f->event('return', '2026-10-02T11:00:00Z', '2026-10-02T11:01:00Z'));
    $r = behaviorReport($s, $f, new BehaviorDefinition('retention', ['contact.created', 'product.viewed'], bins: 3));
    expect($r['result']['cohort_size'])->toBe(1)
        ->and(array_column($r['result']['bins'], 'eligible_subjects'))->toBe([1, 0, 0])
        ->and(array_column($r['result']['bins'], 'retained_subjects'))->toBe([1, null, null])
        ->and($r['result']['cohort_history'])->toBe('selected_period_only');
});

it('compares prior and current activity through a bounded lifecycle window', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('previous', '2026-10-01T10:00:00Z', '2026-10-01T10:01:00Z'));
    $s->project($f->actor, $f->event('current'));
    $r = behaviorReport($s, $f, new BehaviorDefinition('lifecycle', ['product.viewed']));
    expect($r['result']['categories']['continuing'])->toBe(1)
        ->and($r['result']['current_only_is_proven_new_acquisition'])->toBeFalse()
        ->and($r['observation_start_utc'])->toBe('2026-10-01T00:00:00+00:00');
});

it('allows registered channel dimensions and quarantines free text and foreign content references', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('email', payload: ['channel' => 'email']));
    $s->project($f->actor, $f->event('bad', payload: ['channel' => 'Private name']));
    $r = behaviorReport($s, $f, new BehaviorDefinition('performance', ['product.viewed']));
    expect($r['result']['groups'])->toBe([['dimension' => 'email', 'event_type' => 'product.viewed', 'events' => 1, 'unique_subjects' => 1]])
        ->and($r['result']['missing_or_invalid_dimension_events'])->toBe(1)
        ->and(json_encode($r))->not->toContain('Private name');
    $other = new AnalyticsFixture;
    $document = (string) Str::uuid();
    DB::table('content_documents')->insert(['id' => $document, 'workspace_id' => $other->actor->workspaceId,
        'name' => 'Foreign', 'lifecycle' => 'draft', 'created_by_actor_id' => $other->actor->actorId,
        'audit_provenance' => '{}', 'created_at' => $f->now()]);
    $s->project($f->actor, $f->event('foreign-reference', payload: ['content_id' => $document]));
    $content = behaviorReport($s, $f, new BehaviorDefinition('performance', ['product.viewed'], dimension: 'content_id'));
    expect($content['result']['groups'])->toBe([])->and(json_encode($content))->not->toContain($document);
});

it('invalidates old performance reports when their canonical content reference disappears', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $document = (string) Str::uuid();
    DB::table('content_documents')->insert(['id' => $document, 'workspace_id' => $f->actor->workspaceId,
        'name' => 'Own content', 'lifecycle' => 'draft', 'created_by_actor_id' => $f->actor->actorId,
        'audit_provenance' => '{}', 'created_at' => $f->now()]);
    $s->project($f->actor, $f->event('content', payload: ['content_id' => $document]));
    $r = behaviorReport($s, $f, new BehaviorDefinition('performance', ['product.viewed'], dimension: 'content_id'));
    expect($r['result']['groups'][0]['dimension'])->toBe($document);
    DB::table('content_documents')->where('id', $document)->delete();
    expect(fn () => $s->readSnapshot($f->actor, $r['id']))->toThrow(RuntimeException::class);
});

it('rejects future cutoffs, reversed windows and excessive combined observation horizons', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $d = new BehaviorDefinition('lifecycle', ['product.viewed']);
    expect(fn () => $s->behaviorSnapshot($f->actor, $d, new DateTimeImmutable('2026-10-03Z'), new DateTimeImmutable('2026-10-02Z'), $f->now()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->behaviorSnapshot($f->actor, $d, new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now()->modify('+1 second')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->behaviorSnapshot($f->actor, $d, new DateTimeImmutable('2026-09-10Z'), new DateTimeImmutable('2026-10-03Z'), $f->now()))->toThrow(InvalidArgumentException::class);
});

it('keeps same-workspace brand aggregates and stored snapshots separate', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event());
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created']);
    $r = behaviorReport($s, $f, $d);
    $brand = Brand::query()->create([
        'workspace_id' => $f->actor->workspaceId, 'name' => 'Other brand', 'slug' => 'other-brand',
    ]);
    $other = new TenantContext($f->actor->organizationId,
        $f->actor->workspaceId, (string) $brand->getKey(), $f->actor->actorId);
    expect($s->behaviorSnapshot($other, $d, new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now())['result']['entered_subjects'])->toBe(0)
        ->and(fn () => $s->readSnapshot($other, $r['id']))->toThrow(AuthorizationException::class);
});
