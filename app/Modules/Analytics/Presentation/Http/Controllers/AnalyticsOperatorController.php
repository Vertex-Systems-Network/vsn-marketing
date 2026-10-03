<?php

namespace App\Modules\Analytics\Presentation\Http\Controllers;

use App\Modules\Analytics\Application\AnalyticsExplanationGateway;
use App\Modules\Analytics\Application\AnalyticsInsights;
use App\Modules\Analytics\Application\AnalyticsReports;
use App\Modules\Analytics\Application\ScheduledAnalyticsReports;
use App\Modules\Analytics\Domain\AnalyticsAccess;
use App\Modules\Analytics\Domain\AnalyticsExplanation;
use App\Modules\Analytics\Domain\ReportCatalog;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final readonly class AnalyticsOperatorController
{
    public function __construct(private AnalyticsReports $reports, private ScheduledAnalyticsReports $schedules,
        private AnalyticsInsights $insights, private DatabaseManager $database, private Clock $clock, private AnalyticsAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->scope($request);
        $state = 'ready';
        $recent = ['reports' => [], 'invalidated_reports' => 0];
        $schedules = [];
        try {
            $recent = $this->reports->recent($actor);
            $schedules = $this->database->table('analytics_report_schedules')->where('workspace_id', $actor->workspaceId)
                ->where('brand_id', $actor->brandId)->where('actor_id', $actor->actorId)->orderBy('created_at')->limit(20)
                ->get(['id', 'kind', 'enabled', 'next_window_end', 'status_code'])->toArray();
        } catch (AuthorizationException) {
            $state = 'purpose_unavailable';
        }
        // Flash data is a reference to evidence, never a cached authorization grant.
        $insight = null;
        $saved = $request->session()->get('analytics_insight');
        if ($state === 'ready' && is_array($saved)) {
            try {
                if (($saved['action'] ?? null) === 'anomaly') {
                    $insight = $this->insights->anomaly($actor, $saved['snapshot_id'], $saved['baseline']);
                } elseif (($saved['action'] ?? null) === 'explanation') {
                    $insight = $saved['result'];
                    if (isset($insight['output'])) {
                        $insight['output'] = $this->insights->validateExplanation($actor, $saved['snapshot_id'], array_intersect_key($insight['output'], array_flip(['snapshot_id', 'fingerprint', 'facts', 'inferences'])));
                    } else {
                        // Unavailable responses contain no evidence to display.
                        $insight = null;
                    }
                }
            } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
                $insight = null;
            }
        }
        $policy = new AnalyticsExplanation;
        $items = array_map(fn (array $r): array => ['id' => $r['id'], 'fingerprint' => $r['fingerprint'],
            'definition' => $r['definition'], 'definition_hash' => $r['definition_hash'],
            'start_utc' => $r['start_utc'], 'end_utc' => $r['end_utc'], 'receipt_cutoff_utc' => $r['receipt_cutoff_utc'],
            'latest_receipt_utc' => $r['latest_receipt_utc'] ?? null, 'source_completeness' => $r['source_completeness'],
            'excluded' => $r['excluded'], 'lineage_count' => count($r['lineage']),
            'metrics' => $policy->metrics($r), 'quality' => $r['result']['quality'] ?? [],
            'censored_subjects' => $r['result']['censored_subjects'] ?? $r['result']['incomplete_horizon_subjects']
                ?? (isset($r['result']['bins']) ? max(array_column($r['result']['bins'], 'censored_subjects')) : null)], $recent['reports']);
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        return Inertia::render('analytics/operator', ['state' => $state, 'reports' => $items, 'schedules' => $schedules,
            'invalidated_reports' => $recent['invalidated_reports'], 'report_kinds' => ReportCatalog::KINDS,
            'default_start' => $today->modify('-1 day')->format('Y-m-d'), 'default_end' => $today->format('Y-m-d'),
            'notice' => $request->session()->get('analytics_notice'), 'insight' => $insight,
            'explanation_available' => $state === 'ready' && app()->bound(AnalyticsExplanationGateway::class) && $this->access->allows($actor, PermissionCatalog::AI_EXECUTE),
            'actions' => ['generate' => route('analytics.generate', ['workspace' => $actor->workspaceId]),
                'schedules' => route('analytics.schedules', ['workspace' => $actor->workspaceId]),
                'base' => '/workspaces/'.$actor->workspaceId.'/analytics']]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $input = $request->validate(['kind' => 'required|string|in:'.implode(',', ReportCatalog::KINDS),
            'start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d']);
        try {
            $this->reports->generate($this->scope($request), $input['kind'], new DateTimeImmutable($input['start'].'T00:00:00Z'),
                new DateTimeImmutable($input['end'].'T00:00:00Z'));

            return back()->with('analytics_notice', 'report_created');
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
            return back()->with('analytics_notice', 'report_denied');
        }
    }

    public function schedule(Request $request): RedirectResponse
    {
        $input = $request->validate(['kind' => 'required|string|in:'.implode(',', ReportCatalog::KINDS)]);
        try {
            $this->schedules->create($this->scope($request), $input['kind']);

            return back()->with('analytics_notice', 'schedule_created');
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
            return back()->with('analytics_notice', 'schedule_denied');
        }
    }

    public function disable(Request $request, string $workspace, string $schedule): RedirectResponse
    {
        $this->schedules->disable($this->scope($request), $schedule);

        return back()->with('analytics_notice', 'schedule_disabled');
    }

    public function anomaly(Request $request): RedirectResponse
    {
        $input = $request->validate(['snapshot_id' => 'required|uuid', 'baseline' => 'required|array|max:14', 'baseline.*' => 'uuid']);
        try {
            $this->insights->anomaly($this->scope($request), $input['snapshot_id'], $input['baseline']);

            return back()->with('analytics_insight', ['action' => 'anomaly', 'snapshot_id' => $input['snapshot_id'], 'baseline' => $input['baseline']]);
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
            return back()->with('analytics_notice', 'insight_denied');
        }
    }

    public function explain(Request $request): RedirectResponse
    {
        $input = $request->validate(['snapshot_id' => 'required|uuid']);
        if (! app()->bound(AnalyticsExplanationGateway::class)) {
            return back()->with('analytics_notice', 'explanation_unavailable');
        }
        try {
            $insight = app(AnalyticsExplanationGateway::class)->explain($this->scope($request), $input['snapshot_id'], (string) Str::uuid(), 10);

            return back()->with('analytics_insight', ['action' => 'explanation', 'snapshot_id' => $input['snapshot_id'], 'result' => $insight]);
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
            return back()->with('analytics_notice', 'insight_denied');
        }
    }

    private function scope(Request $request): TenantContext
    {
        $actor = $request->attributes->get('tenant_context');
        if (! $actor instanceof TenantContext || $actor->actorId !== (string) $request->user()?->getAuthIdentifier()) {
            throw new AuthorizationException('Canonical analytics tenant context required.');
        }

        return $actor;
    }
}
