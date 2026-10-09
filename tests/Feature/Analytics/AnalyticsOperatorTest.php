<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsQuality;
use App\Modules\Analytics\Application\AnalyticsReports;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Analytics\Domain\ReportCatalog;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('renders authorized aggregate history and denies foreign workspace and missing purpose', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event());
    $r = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $url = '/workspaces/'.$f->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component('analytics/operator')
        ->where('state', 'ready')->has('reports', 1)->where('reports.0.metrics.count', 1)
        ->where('reports.0.source_completeness', 'unknown')->missing('reports.0.lineage')
        ->where('explanation_available', false));
    config(['analytics.purpose_approved' => false]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('state', 'purpose_unavailable')->where('reports', []));
    $other = new AnalyticsFixture;
    $this->get('/workspaces/'.$other->actor->workspaceId.'/analytics')->assertForbidden();
});

it('rechecks flashed measured explanations after consent changes before rendering', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event());
    $r = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $flash = ['action' => 'explanation', 'snapshot_id' => $r['id'], 'result' => ['status' => 'complete', 'output' => [
        'snapshot_id' => $r['id'], 'fingerprint' => $r['fingerprint'], 'facts' => [['metric' => 'count', 'value' => 1]],
        'inferences' => ['source_coverage_unknown'], 'risk_tier' => 'R0', 'causal' => false,
    ]]];
    $url = '/workspaces/'.$f->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->withSession(['analytics_insight' => $flash])->get($url)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('insight.output.facts.0.value', 1));
    $f->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    $this->withSession(['analytics_insight' => $flash])->get($url)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('insight', null)->where('reports', [])->where('invalidated_reports', 1));
});

it('exposes every registered report model with actual bounded measured components', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event('cohort', '2026-10-02T09:00:00Z', '2026-10-02T09:01:00Z', 'contact.created'));
    $facts->project($f->actor, $f->event('view', payload: ['channel' => 'email']));
    $facts->project($f->actor, $f->event('purchase', '2026-10-02T11:00:00Z', '2026-10-02T11:01:00Z', 'order.completed',
        ['transaction_id' => 'operator-purchase', 'amount_minor' => 101, 'currency' => 'USD', 'currency_exponent' => 2]));
    $reports = app(AnalyticsReports::class);
    foreach (ReportCatalog::KINDS as $kind) {
        $reports->generate($f->actor, $kind, new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'));
    }
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get('/workspaces/'.$f->actor->workspaceId.'/analytics')->assertOk()
        ->assertInertia(function (Assert $p) {
            $p->has('reports', 6)->where('reports', function ($reports) {
                $metrics = array_merge(...array_map(fn ($r) => $r['metrics'], $reports->toArray()));

                return ($metrics['count'] ?? null) === 1 && ($metrics['funnel.step.2'] ?? null) === 1
                    && ($metrics['retention.bin.1.returned'] ?? null) === 1
                    && ($metrics['lifecycle.observed_current_only'] ?? null) === 1
                    && ($metrics['performance.email.product.viewed.events'] ?? null) === 1
                    && ($metrics['USD.net'] ?? null) === 101;
            });
        });
});

it('displays authorized quality checks and hides receipt lineage and unapproved source totals', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $f->event('not-projected');
    app(AnalyticsQuality::class)->reconcile($f->actor, 'operator-quality', 'fixture', 'product.viewed',
        new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get('/workspaces/'.$f->actor->workspaceId.'/analytics')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('quality_reports', 1)->where('quality_reports.0.expected_total', null)
            ->where('quality_reports.0.missing_projection', 1)->missing('quality_reports.0.lineage')->missing('quality_reports.0.receipt_lineage'));
    $this->post('/workspaces/'.$f->actor->workspaceId.'/analytics/quality', ['source' => 'fixture', 'event_type' => 'product.viewed',
        'start' => '2026-10-02', 'end' => '2026-10-03'])->assertRedirect()->assertSessionHas('analytics_notice', 'quality_created');
    config(['analytics.purpose_approved' => false]);
    $this->get('/workspaces/'.$f->actor->workspaceId.'/analytics')->assertInertia(fn (Assert $p) => $p->where('quality_reports', []));
});

it('issues only a permission-checked offline autonomy preview from current analytics evidence', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event());
    $report = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'),
        new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $url = '/workspaces/'.$f->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->post($url.'/autonomy/preview', [
            'report_id' => $report['id'], 'target_count' => 12,
        ])->assertRedirect()->assertSessionHas('analytics_notice', 'autonomy_preview_ready');
    $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('autonomy_enabled', true)->where('offline_autonomy_preview.status', 'preview_ready')
        ->where('offline_autonomy_preview.execution_authorized', false)
        ->where('offline_autonomy_preview.stages.execute', 'disabled')
        ->where('offline_autonomy_preview.actions.0.effect', 'read')
        ->where('offline_autonomy_preview.actions.0.source_ids.0', $report['id']));

    $this->post($url.'/autonomy/preview', [
        'report_id' => '11111111-1111-4111-8111-111111111111', 'target_count' => 12,
    ])->assertRedirect()->assertSessionHas('analytics_notice', 'autonomy_preview_denied');
    config(['analytics.purpose_approved' => false]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('autonomy_enabled', false)->where('offline_autonomy_preview', null));
});
