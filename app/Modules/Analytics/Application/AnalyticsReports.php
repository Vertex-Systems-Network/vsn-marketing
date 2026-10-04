<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Analytics\Domain\ReportCatalog;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class AnalyticsReports
{
    public function __construct(private AnalyticsFacts $facts, private ReportCatalog $catalog,
        private DatabaseManager $database, private Clock $clock) {}

    public function generate(TenantContext $actor, string $kind, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $d = $this->catalog->definition($kind);

        return match (true) {
            $d instanceof MetricDefinition => $this->facts->snapshot($actor, $d, $start, $end, $this->clock->now()),
            $d instanceof BehaviorDefinition => $this->facts->behaviorSnapshot($actor, $d, $start, $end, $this->clock->now()),
            default => $this->facts->revenueSnapshot($actor, $d, $start, $end, $this->clock->now()),
        };
    }

    public function recent(TenantContext $actor): array
    {
        $this->facts->authorize($actor);
        $scope = hash('sha256', json_encode([$actor->workspaceId, $actor->brandId], JSON_THROW_ON_ERROR));
        $ids = $this->database->table('analytics_snapshots')->where('workspace_id', $actor->workspaceId)
            ->where('scope_key', $scope)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->pluck('id');
        $reports = [];
        $invalid = 0;
        foreach ($ids as $id) {
            try {
                $reports[] = $this->facts->readSnapshot($actor, $id);
            } catch (RuntimeException) {
                $invalid++;
            }
        }

        return ['reports' => $reports, 'invalidated_reports' => $invalid, 'display_limit' => 20];
    }
}
