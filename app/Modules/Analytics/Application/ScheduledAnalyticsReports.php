<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\ReportCatalog;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class ScheduledAnalyticsReports
{
    public function __construct(private AnalyticsFacts $facts, private AnalyticsReports $reports, private ReportCatalog $catalog,
        private DatabaseManager $database, private Clock $clock, private AuditRecorder $audit) {}

    public function create(TenantContext $actor, string $kind): string
    {
        $this->facts->authorize($actor);
        $definition = $this->catalog->definition($kind);
        $id = (string) Str::uuid();
        $next = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day');
        $this->database->transaction(function () use ($actor, $kind, $definition, $id, $next): void {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            if ($this->database->table('analytics_report_schedules')->where('workspace_id', $actor->workspaceId)->count() >= 20) {
                throw new RuntimeException('Workspace schedule bound reached.');
            }
            $this->database->table('analytics_report_schedules')->insert([
                'id' => $id, 'workspace_id' => $actor->workspaceId, 'organization_id' => $actor->organizationId,
                'brand_id' => $actor->brandId, 'actor_id' => $actor->actorId, 'kind' => $kind,
                'definition_hash' => $definition->fingerprint(), 'next_window_end' => $next,
                'enabled' => true, 'created_at' => $this->clock->now(),
            ]);
            $this->audit->record($actor->workspaceId, 'analytics.schedule_created', ['schedule_id' => $id], $actor->brandId, $actor->actorId);
        }, 3);

        return $id;
    }

    public function disable(TenantContext $actor, string $id): void
    {
        $this->facts->authorize($actor);
        $changed = $this->database->table('analytics_report_schedules')->where('id', $id)
            ->where('workspace_id', $actor->workspaceId)->where('brand_id', $actor->brandId)
            ->where('actor_id', $actor->actorId)->update(['enabled' => false, 'status_code' => 'owner_disabled']);
        if ($changed !== 1) {
            throw new AuthorizationException('Report schedule owner scope denied.');
        }
        $this->audit->record($actor->workspaceId, 'analytics.schedule_disabled', ['schedule_id' => $id], $actor->brandId, $actor->actorId);
    }

    public function due(int $limit = 10): int
    {
        if ($limit < 1 || $limit > 25) {
            throw new InvalidArgumentException('Due report limit outside bound.');
        }
        $ids = $this->database->table('analytics_report_schedules')->where('enabled', true)
            ->where('next_window_end', '<=', $this->clock->now())->orderBy('next_window_end')->orderBy('id')->limit($limit)->pluck('id');
        $generated = 0;
        foreach ($ids as $id) {
            $generated += $this->run($id) ? 1 : 0;
        }

        return $generated;
    }

    public function run(string $id): bool
    {
        $claim = $this->database->transaction(function () use ($id): ?array {
            $schedule = $this->database->table('analytics_report_schedules')->where('id', $id)->lockForUpdate()->first();
            if ($schedule === null || ! $schedule->enabled || new DateTimeImmutable($schedule->next_window_end) > $this->clock->now()) {
                return null;
            }
            $window = new DateTimeImmutable($schedule->next_window_end);
            // Bound recovery to the latest complete UTC day, exposing missed windows instead of inventing backfill totals.
            if ($window < $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-30 days')) {
                $this->database->table('analytics_report_schedules')->where('id', $id)->update(['enabled' => false, 'status_code' => 'recovery_horizon_expired']);

                return null;
            }
            $this->database->table('analytics_report_runs')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'workspace_id' => $schedule->workspace_id, 'schedule_id' => $id,
                'window_end' => $window, 'status' => 'pending', 'attempts' => 0, 'created_at' => $this->clock->now(),
            ]);
            $run = $this->database->table('analytics_report_runs')->where('schedule_id', $id)->where('window_end', $window)->lockForUpdate()->first();
            if ($run === null || $run->status === 'complete'
                || ($run->lease_until !== null && new DateTimeImmutable($run->lease_until) > $this->clock->now())) {
                return null;
            }
            if ($run->attempts >= 3) {
                $this->database->table('analytics_report_runs')->where('id', $run->id)->update([
                    'status' => 'failed', 'failure_code' => 'retry_budget_exhausted', 'claim_token' => null, 'lease_until' => null,
                ]);
                $this->database->table('analytics_report_schedules')->where('id', $id)->update(['enabled' => false, 'status_code' => 'retry_budget_exhausted']);

                return null;
            }
            $token = (string) Str::uuid();
            $this->database->table('analytics_report_runs')->where('id', $run->id)->update([
                'status' => 'running', 'claim_token' => $token, 'lease_until' => $this->clock->now()->modify('+2 minutes'),
                'attempts' => $run->attempts + 1, 'failure_code' => null,
            ]);

            return ['run' => $run->id, 'token' => $token, 'schedule' => $schedule, 'window' => $window];
        }, 3);
        if ($claim === null) {
            return false;
        }
        try {
            return $this->database->transaction(function () use ($claim): bool {
                $schedule = $this->database->table('analytics_report_schedules')->where('id', $claim['schedule']->id)->lockForUpdate()->first();
                $run = $this->database->table('analytics_report_runs')->where('id', $claim['run'])->lockForUpdate()->first();
                if ($run === null || $schedule === null || ! $schedule->enabled || $run->claim_token !== $claim['token']) {
                    return false;
                }
                $actor = new TenantContext($schedule->organization_id, $schedule->workspace_id, $schedule->brand_id, $schedule->actor_id);
                $this->facts->authorize($actor);
                if ($this->catalog->definition($schedule->kind)->fingerprint() !== $schedule->definition_hash) {
                    throw new RuntimeException('Frozen schedule definition changed.');
                }
                $report = $this->reports->generate($actor, $schedule->kind, $claim['window']->modify('-1 day'), $claim['window']);
                $this->database->table('analytics_report_runs')->where('id', $run->id)->update([
                    'status' => 'complete', 'snapshot_id' => $report['id'], 'claim_token' => null, 'lease_until' => null,
                ]);
                $this->database->table('analytics_report_schedules')->where('id', $schedule->id)
                    ->update(['next_window_end' => $claim['window']->modify('+1 day')]);

                return true;
            }, 3);
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException) {
            $this->database->table('analytics_report_runs')->where('id', $claim['run'])->where('claim_token', $claim['token'])
                ->update(['status' => 'failed', 'failure_code' => 'generation_denied', 'claim_token' => null, 'lease_until' => null]);

            return false;
        }
    }
}
