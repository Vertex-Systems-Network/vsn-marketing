<?php

namespace App\Modules\Segmentation\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\ConfirmSegmentProposal;
use App\Modules\Segmentation\Application\ProposeSegment;
use App\Modules\Segmentation\Application\PreviewSegment;
use App\Modules\Segmentation\Application\PublishSegmentVersion;
use App\Modules\Segmentation\Domain\Contracts\SegmentProposalProvider;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class SegmentProposalController
{
    public function __construct(
        private SegmentFieldRegistry $fields,
        private SegmentProposalProvider $provider,
        private DatabaseManager $database,
        private ProposeSegment $proposer,
        private ConfirmSegmentProposal $confirmProposal,
        private PreviewSegment $previewSegment,
        private PublishSegmentVersion $publishVersion,
    ) {}

    public function index(Request $request): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);

        return Inertia::render('segmentation/operator', $this->pageProps($scope));
    }

    public function propose(Request $request): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);
        $intent = $request->input('intent');
        $result = is_string($intent)
            ? $this->proposer->handle($intent, $scope, $actor)
            : ['status' => 'invalid_input', 'code' => 'intent_must_be_text'];

        return Inertia::render('segmentation/operator', $this->pageProps($scope, $result));
    }

    public function store(Request $request): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);
        $definition = $request->input('definition');
        $name = $request->input('name');
        if (! is_array($definition) || ! is_string($name)) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, [
                'status' => 'invalid',
                'code' => 'structured_definition_required',
            ]));
        }

        try {
            $saved = $this->confirmProposal->store(
                name: $name,
                definition: $definition,
                scope: $scope,
                actor: $actor,
                confirmed: $request->boolean('confirmed'),
            );
        } catch (SegmentDefinitionException $exception) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, [
                'status' => 'invalid',
                'code' => $exception->reason,
                'path' => $exception->path,
            ]));
        }

        return Inertia::render('segmentation/operator', $this->pageProps($scope, null, $saved));
    }

    public function preview(Request $request): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);
        $definition = $request->input('definition');
        $segmentId = $request->input('segment_id');
        $version = $request->input('version');
        if ((! is_array($definition) && $segmentId === null)
            || ($segmentId !== null && (! is_string($segmentId) || preg_match('/^[0-9a-f-]{36}$/i', $segmentId) !== 1))
            || ($version !== null && filter_var($version, FILTER_VALIDATE_INT) === false)) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, [
                'status' => 'invalid', 'code' => 'structured_definition_required',
            ]));
        }
        try {
            $preview = $this->previewSegment->evaluate(
                is_array($definition) ? $definition : [], $scope, $actor,
                $segmentId, $version === null ? null : (int) $version,
            );
        } catch (SegmentDefinitionException $exception) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, [
                'status' => 'invalid', 'code' => $exception->reason, 'path' => $exception->path,
            ]));
        }

        return Inertia::render('segmentation/operator', $this->pageProps($scope, null, null, $preview));
    }

    public function revise(Request $request, string $workspace, string $segment): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);
        $definition = $request->input('definition');
        if (! is_array($definition)) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'structured_definition_required']));
        }
        try {
            $saved = $this->confirmProposal->revise($segment, $definition, $scope, $actor, $request->boolean('confirmed'));
        } catch (SegmentDefinitionException $exception) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => $exception->reason]));
        }

        return Inertia::render('segmentation/operator', $this->pageProps($scope, null, $saved));
    }

    public function publish(Request $request, string $workspace, string $segment): Response
    {
        [$scope, $actor] = $this->authorizedContext($request);
        $version = filter_var($request->input('version'), FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => 'version_required']));
        }
        try {
            $published = $this->publishVersion->publish($segment, $version, $scope, $actor, $request->boolean('confirmed'));
        } catch (SegmentDefinitionException $exception) {
            return Inertia::render('segmentation/operator', $this->pageProps($scope, ['status' => 'invalid', 'code' => $exception->reason]));
        }

        return Inertia::render('segmentation/operator', $this->pageProps($scope, null, $published + ['published' => true]));
    }

    /** @return array{0: TenantContext, 1: User} */
    private function authorizedContext(Request $request): array
    {
        $scope = $request->attributes->get('tenant_context');
        $actor = $request->user();
        if (! $scope instanceof TenantContext || ! $actor instanceof User || (string) $actor->getKey() !== $scope->actorId) {
            throw new AuthorizationException('Segmentation tenant context is required.');
        }

        return [$scope, $actor];
    }

    /** @param array<string, mixed>|null $proposal @param array{id: string, version: int, hash: string}|null $saved @return array<string, mixed> */
    private function pageProps(TenantContext $scope, ?array $proposal = null, ?array $saved = null, ?array $preview = null): array
    {
        $segments = $this->database->table('segment_definitions')
            ->where('workspace_id', $scope->workspaceId)->orderBy('created_at', 'desc')
            ->limit(50)->get(['id', 'name', 'status', 'published_version_number'])
            ->map(function (object $segment) use ($scope): array {
                $latest = $this->database->table('segment_definition_versions')
                    ->where('workspace_id', $scope->workspaceId)->where('definition_id', $segment->id)
                    ->orderBy('version_number', 'desc')->first(['version_number', 'definition_ast', 'definition_hash']);

                return [
                    'id' => $segment->id, 'name' => $segment->name, 'status' => $segment->status,
                    'published_version' => $segment->published_version_number,
                    'latest_version' => $latest?->version_number,
                    'latest_hash' => $latest?->definition_hash,
                    'latest_definition' => $latest === null ? null : (is_string($latest->definition_ast)
                        ? json_decode($latest->definition_ast, true) : $latest->definition_ast),
                ];
            })->all();
        $options = [];
        foreach ($this->fields->availableTo([PermissionCatalog::CONTACT_READ]) as $id => $field) {
            $options[] = [
                'id' => $id,
                'type' => $field['type'],
                'operators' => $field['type'] === 'timestamp'
                    ? ['before', 'after', 'on_or_before', 'on_or_after', 'is_set', 'is_not_set']
                    : ['equals', 'not_equals', 'is_set', 'is_not_set'],
            ];
        }

        $eventLimit = max(1, (int) config('segmentation.max_proposal_events', 250));
        $eventNames = $this->database->table('event_types')
            ->where('workspace_id', $scope->workspaceId)
            ->orderBy('canonical_name')
            ->limit($eventLimit)
            ->pluck('canonical_name')
            ->all();
        $eventNames = array_values(array_unique(array_filter(
            $eventNames,
            static fn (mixed $name): bool => is_string($name) && preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $name) === 1,
        )));

        return [
            'workspace_id' => $scope->workspaceId,
            'proposal_available' => $this->provider->available(),
            'fields' => $options,
            'registered_events' => $eventNames,
            'proposal_result' => $proposal,
            'saved_segment' => $saved,
            'segments' => $segments,
            'preview_result' => $preview,
            'actions' => [
                'propose' => url('/workspaces/'.$scope->workspaceId.'/segments/proposals'),
                'store' => url('/workspaces/'.$scope->workspaceId.'/segments/versions'),
                'preview' => url('/workspaces/'.$scope->workspaceId.'/segments/preview'),
                'revise_base' => url('/workspaces/'.$scope->workspaceId.'/segments'),
            ],
        ];
    }
}
