<?php

namespace App\Modules\Segmentation\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\ConfirmSegmentProposal;
use App\Modules\Segmentation\Application\ProposeSegment;
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
    private function pageProps(TenantContext $scope, ?array $proposal = null, ?array $saved = null): array
    {
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
            'actions' => [
                'propose' => url('/workspaces/'.$scope->workspaceId.'/segments/proposals'),
                'store' => url('/workspaces/'.$scope->workspaceId.'/segments/versions'),
            ],
        ];
    }
}
