<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsQuality;
use App\Modules\Analytics\Domain\AnalyticsSourceVerifier;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function qualityInput(): array
{
    return ['fixture', 'product.viewed', new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), new DateTimeImmutable('2026-10-03T12:00:00Z')];
}

function qualityCheckpoint(AnalyticsFixture $f): array
{
    $scope = hash('sha256', json_encode([$f->actor->workspaceId, $f->actor->brandId], JSON_THROW_ON_ERROR));

    return ['scope' => $scope, 'source' => 'fixture', 'event_type' => 'product.viewed', 'start' => '2026-10-02T00:00:00+00:00',
        'end' => '2026-10-03T00:00:00+00:00', 'cutoff' => '2026-10-03T12:00:00+00:00',
        'keys' => DB::table('analytics_facts')->where('workspace_id', $f->actor->workspaceId)->pluck('envelope_hash', 'source_key')->all(),
        'reference' => hash('sha256', 'independent-test-fixture')];
}

it('keeps unknown totals and checkpoints immutable while disclosing local missing and late receipts', function () {
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event('projected'));
    $f->event('unprojected', received: '2026-10-03T11:00:00Z');
    $q = app(AnalyticsQuality::class);
    $r = $q->reconcile($f->actor, 'unknown', ...qualityInput());
    expect($r['expected_total'])->toBeNull()->and($r['coverage'])->toBe('unknown')
        ->and($r['missing_projection'])->toBe(1)->and($r['late'])->toBe(1)
        ->and($r['max_receipt_lag_seconds'])->toBe(90000)
        ->and($q->reconcile($f->actor, 'unknown', ...qualityInput()))->toBe($r)
        ->and(DB::table('analytics_reconciliations')->count())->toBe(1);
    $facts->project($f->actor, $f->event('new'));
    expect($q->read($f->actor, $r['id']))->toBe($r)
        ->and($q->reconcile($f->actor, 'new-version', ...qualityInput())['observed_keys'])->toBe(3);
    $input = qualityInput();
    $input[1] = 'cart.created';
    expect(fn () => $q->reconcile($f->actor, 'unknown', ...$input))->toThrow(RuntimeException::class);
});

it('requires exact independently verified scope and rejects fabricated expected totals', function () {
    $f = new AnalyticsFixture;
    app(AnalyticsFacts::class)->project($f->actor, $f->event('one'));
    $c = qualityCheckpoint($f);
    expect(fn () => app(AnalyticsQuality::class)->reconcile($f->actor, 'forged', ...[...qualityInput(), $c]))->toThrow(AuthorizationException::class);
    app()->instance(AnalyticsSourceVerifier::class, new class implements AnalyticsSourceVerifier
    {
        public function verifies(TenantContext $actor, array $checkpoint): bool
        {
            return true; // Explicit synthetic fixture; never a production verifier.
        }
    });
    $q = app(AnalyticsQuality::class);
    $r = $q->reconcile($f->actor, 'verified', ...[...qualityInput(), $c]);
    expect($r['expected_total'])->toBe(1)->and($r['coverage'])->toBe('checkpoint_matched')
        ->and($r['source_truth_certified'])->toBeFalse();
    $c['keys'][hash('sha256', 'missing')] = hash('sha256', 'missing-envelope');
    $c['keys'][array_key_first($c['keys'])] = hash('sha256', 'changed-envelope');
    $r = $q->reconcile($f->actor, 'discrepant', ...[...qualityInput(), $c]);
    expect($r['coverage'])->toBe('discrepant')->and($r['missing_source_keys'])->toBe(1)->and($r['drifted_hashes'])->toBe(1);
    $c['scope'] = hash('sha256', 'foreign-brand');
    expect(fn () => $q->reconcile($f->actor, 'foreign-manifest', ...[...qualityInput(), $c]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('analytics_reconciliations')->count())->toBe(2);
});

it('shows conflicting and duplicate receipts without rewriting original evidence or leaking payloads', function () {
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event('same'));
    $snapshot = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), qualityInput()[2], qualityInput()[3], $f->now());
    expect($facts->project($f->actor, $f->event('same')))->toBe('conflict');
    $q = app(AnalyticsQuality::class);
    $r = $q->reconcile($f->actor, 'conflict', ...qualityInput());
    expect($r['conflicts'])->toBe(1)->and($r['duplicates'])->toBe(1)->and($r['missing_projection'])->toBe(1)
        ->and($r['affected_metric_versions'][0]['definition_hash'])->toBe($snapshot['definition_hash'])
        ->and($q->read($f->actor, $r['id']))->toBe($r);
    $display = json_encode($q->recent($f->actor), JSON_THROW_ON_ERROR);
    expect($display)->not->toContain('Private name', $f->contact, 'receipt_lineage', 'source_event_id');
    expect(DB::table('analytics_snapshots')->where('id', $snapshot['id'])->value('fingerprint'))->toBe($snapshot['fingerprint']);
});

it('rechecks unprojected receipt consent, foreign scope and erasure before read or replay', function () {
    $f = new AnalyticsFixture;
    $f->event('unprojected');
    $q = app(AnalyticsQuality::class);
    $r = $q->reconcile($f->actor, 'privacy', ...qualityInput());
    $other = new AnalyticsFixture;
    expect(fn () => $q->read($other->actor, $r['id']))->toThrow(AuthorizationException::class);
    $f->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    expect(fn () => $q->read($f->actor, $r['id']))->toThrow(RuntimeException::class)
        ->and(fn () => $q->reconcile($f->actor, 'privacy', ...qualityInput()))->toThrow(RuntimeException::class);
    $f->consent(ConsentDecision::Granted, '2026-10-03T11:30:00Z');
    app(AnalyticsFacts::class)->invalidateSubject($f->actor, $f->contact);
    expect(DB::table('analytics_reconciliations')->count())->toBe(0)
        ->and($q->reconcile($f->actor, 'after-erasure', ...qualityInput())['observed_keys'])->toBe(0);
});

it('rolls back interrupted reconciliation and replays to one report with original receipt evidence', function () {
    $f = new AnalyticsFixture;
    $f->event('one');
    $q = app(AnalyticsQuality::class);
    DB::beginTransaction();
    $r = $q->reconcile($f->actor, 'retry', ...qualityInput());
    DB::rollBack();
    expect(DB::table('analytics_reconciliations')->count())->toBe(0);
    $retry = $q->reconcile($f->actor, 'retry', ...qualityInput());
    expect($retry)->toBe($r)->and($q->reconcile($f->actor, 'retry', ...qualityInput()))->toBe($r)
        ->and(DB::table('analytics_reconciliations')->count())->toBe(1);
    DB::table('analytics_reconciliations')->where('id', $r['id'])->update(['report' => '{}']);
    expect(fn () => $q->read($f->actor, $r['id']))->toThrow(RuntimeException::class);
});

it('retries known migration but refuses partial schema or deletion of retained quality evidence', function () {
    $f = new AnalyticsFixture;
    $migration = require database_path('migrations/2026_10_03_000003_create_analytics_reconciliations.php');
    $migration->up();
    app(AnalyticsQuality::class)->reconcile($f->actor, 'retained', ...qualityInput());
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('refuses unknown partial reconciliation DDL without repairing or deleting it', function () {
    $migration = require database_path('migrations/2026_10_03_000003_create_analytics_reconciliations.php');
    $migration->down();
    Schema::create('analytics_reconciliations', function (Blueprint $table): void {
        $table->char('id', 64)->primary();
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(Schema::hasTable('analytics_reconciliations'))->toBeTrue()
        ->and(Schema::hasColumn('analytics_reconciliations', 'report'))->toBeFalse();
});
