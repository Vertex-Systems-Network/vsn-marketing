<?php

namespace App\Modules\AI\Presentation\Http\Controllers;

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamMonitor;
use App\Modules\AI\Infrastructure\DatabaseOfflineRedTeamTriage;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Infrastructure\WorkspaceExperimentAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use JsonException;

final readonly class OfflineRedTeamIncidentController
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private AuditRecorder $audit) {}

    public function page(Request $request, string $workspace, string $run): Response
    {
        $actor = $request->attributes->get('tenant_context');
        $user = $request->user();
        if (! $actor instanceof TenantContext || ! $user instanceof User
            || $actor->workspaceId !== $workspace) {
            abort(403);
        }
        $access = new WorkspaceExperimentAccess($this->authorizer, $user);
        try {
            $monitor = new DatabaseBoundedAutonomyOfflineRedTeamMonitor($access);
            $review = $monitor->inspect($actor, $run);
            $triage = (new DatabaseOfflineRedTeamTriage($monitor, $access, $this->audit))
                ->inspect($actor, $run);
        } catch (InvalidArgumentException|JsonException) {
            $review = null;
            $triage = null;
        }

        $independent = $review !== null && $review['latest_audit_event_id'] !== null
            && DB::table('audit_events')->where('id', $review['latest_audit_event_id'])
                ->where('workspace_id', $actor->workspaceId)->where('brand_id', $actor->brandId)
                ->where('action', 'ai.autonomy.redteam.offline.review')
                ->where('actor_id', '!=', $actor->actorId)->exists();

        return Inertia::render('ai/offline-incident', [
            'review' => $review,
            'triage' => $triage,
            'triage_available' => $independent && $access->allows($actor, PermissionCatalog::CAMPAIGN_APPROVE),
            'triage_action' => route('autonomy.offline-incidents.triage', [
                'workspace' => $workspace, 'run' => $run,
            ]),
            'notice' => $request->session()->get('offline_incident_notice'),
        ]);
    }

    public function __invoke(Request $request, string $workspace, string $run): JsonResponse
    {
        $actor = $request->attributes->get('tenant_context');
        $user = $request->user();
        if (! $actor instanceof TenantContext || ! $user instanceof User
            || $actor->workspaceId !== $workspace) {
            abort(403);
        }

        try {
            $monitor = new DatabaseBoundedAutonomyOfflineRedTeamMonitor(
                new WorkspaceExperimentAccess($this->authorizer, $user),
            );

            return response()->json($monitor->inspect($actor, $run))->header('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|JsonException) {
            // Corrupt or untrusted audit evidence must not be emitted to the client.
            return response()->json(['status' => 'held_offline', 'reason_code' => 'audit_review_unavailable'], 422)
                ->header('Cache-Control', 'no-store');
        }
    }
}
