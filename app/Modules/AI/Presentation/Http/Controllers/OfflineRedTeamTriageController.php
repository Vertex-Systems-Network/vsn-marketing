<?php

namespace App\Modules\AI\Presentation\Http\Controllers;

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamMonitor;
use App\Modules\AI\Infrastructure\DatabaseOfflineRedTeamTriage;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Infrastructure\WorkspaceExperimentAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

final readonly class OfflineRedTeamTriageController
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private AuditRecorder $audit) {}

    public function __invoke(Request $request, string $workspace, string $run): JsonResponse|RedirectResponse
    {
        $operator = $request->attributes->get('tenant_context');
        $user = $request->user();
        if (! $operator instanceof TenantContext || ! $user instanceof User
            || $operator->workspaceId !== $workspace) {
            abort(403);
        }
        $input = $request->validate([
            'audit_event_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:investigating,escalated'],
        ]);
        $access = new WorkspaceExperimentAccess($this->authorizer, $user);
        $triage = new DatabaseOfflineRedTeamTriage(
            new DatabaseBoundedAutonomyOfflineRedTeamMonitor($access), $access, $this->audit,
        );
        try {
            $result = $triage->record(
                $operator, $run, $input['audit_event_id'], $input['decision'],
            );
            if ($request->header('X-Inertia')) {
                return redirect()->route('autonomy.offline-incidents.review', compact('workspace', 'run'))
                    ->with('offline_incident_notice', $result['decision']);
            }

            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|JsonException) {
            if ($request->header('X-Inertia')) {
                return redirect()->route('autonomy.offline-incidents.review', compact('workspace', 'run'))
                    ->with('offline_incident_notice', 'unavailable');
            }

            return response()->json(['status' => 'held_offline', 'reason_code' => 'triage_review_unavailable'], 422)
                ->header('Cache-Control', 'no-store');
        }
    }
}
