<?php

namespace App\Modules\Providers\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Application\Community\CommunityInbox;
use App\Modules\Providers\Domain\Community\CommunityAccess;
use App\Modules\Providers\Domain\Community\CommunityModerationState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CommunityOperatorController
{
    public function __construct(
        private CommunityInbox $inbox,
        private CommunityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('community/operator', [
            'items' => $this->inbox->recent($actor),
            'permissions' => [
                'can_moderate' => $this->access->allows($actor, PermissionCatalog::COMMUNITY_MODERATE),
                'can_propose' => $this->access->allows($actor, PermissionCatalog::AI_EXECUTE),
                'can_approve_ai' => $this->access->allows($actor, PermissionCatalog::AI_APPROVE),
            ],
            'notice' => $request->session()->get('community_notice'),
            'actions' => ['base' => '/workspaces/'.$actor->workspaceId.'/community'],
        ]);
    }

    public function assign(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['assignee_actor_id' => ['required', 'string', 'max:191']]);
        $this->inbox->assign($actor, $item, (string) $validated['assignee_actor_id']);

        return $this->back($actor, 'Community item assigned.');
    }

    public function moderate(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'state' => ['required', Rule::enum(CommunityModerationState::class)],
        ]);
        $this->inbox->moderate($actor, $item, CommunityModerationState::from((string) $validated['state']));

        return $this->back($actor, 'Community moderation state updated.');
    }

    public function propose(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $this->inbox->propose($actor, $item, (string) $validated['text']);

        return $this->back($actor, 'AI response proposal saved for operator review.');
    }

    public function approve(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->inbox->approveProposal($actor, $item);

        return $this->back($actor, 'AI response proposal approved. No provider send was performed.');
    }

    public function reject(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->inbox->rejectProposal($actor, $item);

        return $this->back($actor, 'AI response proposal rejected.');
    }

    private function actor(Request $request): TenantContext
    {
        $actor = $request->attributes->get('tenant_context');
        if (! $actor instanceof TenantContext) {
            throw new AuthorizationException('Community tenant context is required.');
        }

        return $actor;
    }

    private function back(TenantContext $actor, string $notice): RedirectResponse
    {
        return redirect()->route('community.operator', ['workspace' => $actor->workspaceId])
            ->with('community_notice', $notice);
    }
}
