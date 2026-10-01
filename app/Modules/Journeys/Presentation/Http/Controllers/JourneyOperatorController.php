<?php

namespace App\Modules\Journeys\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Journeys\Application\JourneyRegistry;
use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final readonly class JourneyOperatorController
{
    public function __construct(
        private DatabaseManager $database,
        private WorkspaceAuthorizer $authorizer,
        private JourneyGraphValidator $validator,
        private JourneyRegistry $registry,
        private JourneyNodeAttemptRepository $attempts,
    ) {}

    public function index(Request $request): Response
    {
        [$scope] = $this->authorizedContext($request, PermissionCatalog::JOURNEY_READ);

        return Inertia::render('journeys/operator', $this->pageProps($scope));
    }

    public function create(Request $request): Response
    {
        [$scope] = $this->authorizedContext($request, PermissionCatalog::JOURNEY_CREATE);
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 191) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'journey_name_required']));
        }
        $id = (string) Str::uuid();
        $now = now();
        $this->database->table('journeys')->insert([
            'id' => $id, 'workspace_id' => $scope->workspaceId, 'name' => $name, 'status' => 'draft',
            'draft_graph' => json_encode($this->starterGraph(), JSON_THROW_ON_ERROR),
            'draft_revision' => 1, 'lifecycle_revision' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'created', 'journey_id' => $id]));
    }

    public function saveDraft(Request $request, string $workspace, string $journey): Response
    {
        [$scope] = $this->authorizedContext($request, PermissionCatalog::JOURNEY_CREATE);
        $name = trim((string) $request->input('name', ''));
        $graph = $request->input('graph');
        $expected = filter_var($request->input('expected_revision'), FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 191 || ! is_array($graph) || $expected === false || $expected < 1) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'draft_payload_invalid']));
        }
        try {
            $canonical = $this->validator->normalize($graph);
        } catch (JourneyDefinitionException $error) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => $error->reason, 'path' => $error->path]));
        }
        $updated = $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)->where('id', $journey)
            ->where('status', 'draft')->where('draft_revision', $expected)->update([
                'name' => $name, 'draft_graph' => json_encode($canonical, JSON_THROW_ON_ERROR),
                'draft_revision' => $expected + 1, 'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'stale', 'code' => 'draft_revision_conflict']));
        }

        return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'saved', 'journey_id' => $journey]));
    }

    public function publish(Request $request, string $workspace, string $journey): Response
    {
        [$scope, $actor] = $this->authorizedContext($request, PermissionCatalog::JOURNEY_PUBLISH);
        $expected = filter_var($request->input('expected_revision'), FILTER_VALIDATE_INT);
        $confirmed = $request->boolean('confirmed');
        if ($expected === false || $expected < 1 || ! $confirmed) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'explicit_publish_confirmation_required']));
        }

        try {
            $result = $this->database->transaction(function () use ($scope, $actor, $journey, $expected): array {
                $record = $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)->where('id', $journey)->lockForUpdate()->first();
                if ($record === null || $record->status !== 'draft' || (int) $record->draft_revision !== $expected || $record->draft_graph === null) {
                    throw new \DomainException('journey_draft_stale');
                }
                $latest = $this->database->table('journey_versions')->where('workspace_id', $scope->workspaceId)
                    ->where('journey_id', $journey)->max('version_number');
                $graph = is_string($record->draft_graph) ? json_decode($record->draft_graph, true, 512, JSON_THROW_ON_ERROR) : $record->draft_graph;
                $published = $this->registry->publish(
                    $journey, ((int) $latest) + 1, $scope, $actor, $graph, true,
                );
                $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)->where('id', $journey)->update([
                    'status' => 'published', 'lifecycle_revision' => (int) $record->lifecycle_revision + 1, 'updated_at' => now(),
                ]);

                return ['version' => $published['version_number'], 'hash' => $published['definition']->hash];
            });
        } catch (\DomainException $error) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'stale', 'code' => $error->getMessage()]));
        }

        return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'published'] + $result));
    }

    public function lifecycle(Request $request, string $workspace, string $journey, string $action): Response
    {
        $permission = PermissionCatalog::JOURNEY_PUBLISH;
        [$scope] = $this->authorizedContext($request, $permission);
        $expected = filter_var($request->input('expected_revision'), FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0 || ! $request->boolean('confirmed')) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'versioned_lifecycle_confirmation_required']));
        }
        $transitions = [
            'activate' => ['published', 'active'], 'pause' => ['active', 'paused'],
            'resume' => ['paused', 'active'], 'cancel' => [['published', 'active', 'paused'], 'cancelled'],
            'archive' => [['draft', 'published', 'paused', 'cancelled'], 'archived'],
        ];
        if (! isset($transitions[$action])) {
            abort(404);
        }
        [$from, $to] = $transitions[$action];
        $updated = $this->database->transaction(function () use ($scope, $journey, $expected, $action, $from, $to): int {
            $record = $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)->where('id', $journey)->lockForUpdate()->first();
            $allowed = is_array($from) ? in_array($record?->status, $from, true) : $record?->status === $from;
            if ($record === null || ! $allowed || (int) $record->lifecycle_revision !== $expected) {
                return 0;
            }
            $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)->where('id', $journey)->update([
                'status' => $to, 'lifecycle_revision' => $expected + 1, 'updated_at' => now(),
            ]);
            if ($action === 'cancel') {
                $executionIds = $this->database->table('journey_executions')->join('journey_versions', function ($join): void {
                    $join->on('journey_versions.id', '=', 'journey_executions.journey_version_id')
                        ->on('journey_versions.workspace_id', '=', 'journey_executions.workspace_id');
                })->where('journey_versions.workspace_id', $scope->workspaceId)->where('journey_versions.journey_id', $journey)
                    ->whereIn('journey_executions.status', ['queued', 'running', 'waiting'])->pluck('journey_executions.id')->all();
                if ($executionIds !== []) {
                    foreach ($executionIds as $executionId) {
                        $this->attempts->cancelExecution($scope->workspaceId, (string) $executionId, new \DateTimeImmutable('now'));
                    }
                    if (Schema::hasTable('journey_work_items')) {
                        $this->database->table('journey_work_items')->where('workspace_id', $scope->workspaceId)->whereIn('execution_id', $executionIds)
                            ->whereIn('status', ['pending', 'running', 'waiting'])->update(['status' => 'cancelled', 'updated_at' => now()]);
                    }
                    $this->database->table('journey_waits')->where('workspace_id', $scope->workspaceId)
                        ->whereIn('execution_id', $executionIds)->where('status', 'pending')
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
                $this->database->table('journey_enrollments')->where('workspace_id', $scope->workspaceId)
                    ->whereIn('status', ['active', 'waiting'])->whereIn('journey_version_id', function ($query) use ($scope, $journey): void {
                        $query->select('id')->from('journey_versions')->where('workspace_id', $scope->workspaceId)->where('journey_id', $journey);
                    })->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            return 1;
        });
        if ($updated !== 1) {
            return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => 'stale', 'code' => 'lifecycle_revision_conflict']));
        }

        return Inertia::render('journeys/operator', $this->pageProps($scope, ['status' => $to]));
    }

    /** @return array{0: TenantContext, 1: User} */
    private function authorizedContext(Request $request, string $permission): array
    {
        $scope = $request->attributes->get('tenant_context');
        $actor = $request->user();
        if (! $scope instanceof TenantContext || ! $actor instanceof User || (string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, $permission)) {
            throw new AuthorizationException('Journey workspace authority is required.');
        }

        return [$scope, $actor];
    }

    /** @return array<string, mixed> */
    private function pageProps(TenantContext $scope, ?array $notice = null): array
    {
        $journeys = $this->database->table('journeys')->where('workspace_id', $scope->workspaceId)
            ->orderByDesc('updated_at')->limit(100)->get()->map(function (object $journey) use ($scope): array {
                $version = $this->database->table('journey_versions')->where('workspace_id', $scope->workspaceId)
                    ->where('journey_id', $journey->id)->orderByDesc('version_number')->first();
                $graph = $journey->draft_graph;
                if ($graph !== null && is_string($graph)) {
                    $graph = json_decode($graph, true);
                }
                if (! is_array($graph) && $version !== null) {
                    $graph = is_string($version->graph) ? json_decode($version->graph, true) : $version->graph;
                }

                return [
                    'id' => (string) $journey->id, 'name' => (string) $journey->name,
                    'status' => (string) $journey->status, 'draft_revision' => (int) $journey->draft_revision,
                    'lifecycle_revision' => (int) $journey->lifecycle_revision,
                    'version' => $version === null ? null : (int) $version->version_number,
                    'hash' => $version?->definition_hash, 'graph' => $graph,
                ];
            })->all();
        $timelineRows = $this->database->table('journey_executions')->join('journey_versions', function ($join): void {
            $join->on('journey_versions.id', '=', 'journey_executions.journey_version_id')
                ->on('journey_versions.workspace_id', '=', 'journey_executions.workspace_id');
        })->where('journey_executions.workspace_id', $scope->workspaceId)
            ->orderByDesc('journey_executions.created_at')->limit(100)
            ->get(['journey_executions.id', 'journey_versions.journey_id', 'journey_versions.version_number', 'journey_executions.status', 'journey_executions.revision', 'journey_executions.created_at', 'journey_executions.updated_at']);
        $histories = collect();
        if (! $timelineRows->isEmpty()) {
            $rankedHistory = $this->database->table('journey_execution_transitions')
                ->select(['execution_id', 'event_type', 'node_id', 'created_at', 'transition_revision'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY execution_id ORDER BY transition_revision DESC) AS history_rank')
                ->where('workspace_id', $scope->workspaceId)
                ->whereIn('execution_id', $timelineRows->pluck('id')->all());
            $histories = $this->database->connection()->query()->fromSub($rankedHistory, 'bounded_execution_history')
                ->where('history_rank', '<=', 8)
                ->orderBy('execution_id')->orderBy('transition_revision')
                ->get(['execution_id', 'event_type', 'node_id', 'created_at'])
                ->groupBy('execution_id');
        }
        $timeline = $timelineRows->map(fn (object $row): array => [
                'id' => (string) $row->id, 'journey_id' => (string) $row->journey_id,
                'version' => (int) $row->version_number, 'status' => (string) $row->status,
                'revision' => (int) $row->revision, 'history' => ($histories->get($row->id) ?? collect())->map(fn (object $event): array => [
                    'status' => (string) $event->event_type, 'node_id' => $event->node_id === null ? null : (string) $event->node_id,
                    'at' => (string) $event->created_at,
                ])->all(),
                'created_at' => (string) $row->created_at, 'updated_at' => (string) $row->updated_at,
            ])->all();

        return [
            'workspace_id' => $scope->workspaceId, 'journeys' => $journeys, 'timeline' => $timeline, 'notice' => $notice,
            'actions' => ['create' => url('/workspaces/'.$scope->workspaceId.'/journeys'),
                'base' => url('/workspaces/'.$scope->workspaceId.'/journeys')],
        ];
    }

    /** @return array{schema_version:int,nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>} */
    private function starterGraph(): array
    {
        return ['schema_version' => 1, 'nodes' => [
            ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'finish', 'type' => 'end'],
        ], 'edges' => [['from' => 'entry', 'to' => 'finish']]];
    }
}
