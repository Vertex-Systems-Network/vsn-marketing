<?php

use App\Modules\AI\Presentation\Http\Controllers\OfflineRedTeamIncidentController;
use App\Modules\AI\Presentation\Http\Controllers\OfflineRedTeamTriageController;
use App\Modules\Analytics\Presentation\Http\Controllers\AnalyticsOperatorController;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Presentation\Http\Controllers\AuditExportController;
use App\Modules\Identity\Presentation\Http\Controllers\EnterpriseOidcController;
use App\Modules\Identity\Presentation\Http\Controllers\EnterpriseOverviewController;
use App\Modules\Identity\Presentation\Http\Controllers\FederationBindingReviewController;
use App\Modules\Identity\Presentation\Http\Controllers\ProvisioningCredentialController;
use App\Modules\Identity\Presentation\Http\Controllers\RevokeWorkspaceSessionsController;
use App\Modules\Identity\Presentation\Http\Controllers\RoleAssignmentReviewController;
use App\Modules\Identity\Presentation\Http\Controllers\SessionController;
use App\Modules\Identity\Presentation\Http\Controllers\WorkspaceDirectoryController;
use App\Modules\Journeys\Presentation\Http\Controllers\JourneyOperatorController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingBulkApprovalController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingOperatorController;
use App\Modules\Segmentation\Presentation\Http\Controllers\SegmentProposalController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::middleware('guest')->get('/auth/login', fn () => Inertia\Inertia::render('auth/login'))->name('login');
Route::middleware(['guest', 'throttle:login'])->group(function (): void {
    Route::get('/auth/enterprise/{workspace}/login', [EnterpriseOidcController::class, 'start'])->name('enterprise.oidc.start');
    Route::get('/auth/enterprise/{workspace}/callback', [EnterpriseOidcController::class, 'callback'])->name('enterprise.oidc.callback');
});
Route::middleware(['auth', 'throttle:60,1'])->get('/workspaces', WorkspaceDirectoryController::class)->name('workspaces.directory');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::AUDIT_EXPORT, 'throttle:10,1'])
    ->get('/workspaces/{workspace}/audit/export', AuditExportController::class)
    ->name('workspace.audit.export');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::AUDIT_EXPORT])
    ->get('/workspaces/{workspace}/enterprise', EnterpriseOverviewController::class)
    ->name('workspace.enterprise.overview');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::IDENTITY_MANAGE, 'throttle:10,1'])
    ->group(function (): void {
        $bindings = FederationBindingReviewController::class;
        Route::post('/workspaces/{workspace}/identity/federation-bindings', [$bindings, 'propose']);
        Route::post('/workspaces/{workspace}/identity/federation-bindings/{proposal}/approve', [$bindings, 'approve']);
        Route::post('/workspaces/{workspace}/identity/federation-bindings/{proposal}/apply', [$bindings, 'apply']);
        $controller = RoleAssignmentReviewController::class;
        Route::post('/workspaces/{workspace}/identity/role-proposals', [$controller, 'propose']);
        Route::post('/workspaces/{workspace}/identity/role-proposals/{proposal}/approve', [$controller, 'approve']);
        Route::post('/workspaces/{workspace}/identity/role-proposals/{proposal}/apply', [$controller, 'apply']);
    });
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::IDENTITY_MANAGE, 'throttle:10,1'])
    ->post('/workspaces/{workspace}/identity/members/{user}/revoke-sessions', RevokeWorkspaceSessionsController::class)
    ->name('workspace.identity.sessions.revoke');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::IDENTITY_MANAGE, 'throttle:10,1'])
    ->group(function (): void {
        Route::post('/workspaces/{workspace}/identity/provisioning-credentials', [ProvisioningCredentialController::class, 'issue']);
        Route::post('/workspaces/{workspace}/identity/provisioning-credentials/{credential}/rotate', [ProvisioningCredentialController::class, 'rotate']);
        Route::delete('/workspaces/{workspace}/identity/provisioning-credentials/{credential}', [ProvisioningCredentialController::class, 'revoke']);
    });

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_READ, 'throttle:30,1'])
    ->get('/workspaces/{workspace}/autonomy/offline-incidents/{run}', OfflineRedTeamIncidentController::class)
    ->name('autonomy.offline-incidents.show');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_READ, 'throttle:30,1'])
    ->get('/workspaces/{workspace}/autonomy/offline-incidents/{run}/review', [OfflineRedTeamIncidentController::class, 'page'])
    ->name('autonomy.offline-incidents.review');

Route::middleware([
    'auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_READ,
    'workspace.permission:'.PermissionCatalog::CAMPAIGN_APPROVE, 'throttle:10,1',
])->post('/workspaces/{workspace}/autonomy/offline-incidents/{run}/triage', OfflineRedTeamTriageController::class)
    ->name('autonomy.offline-incidents.triage');

Route::middleware(['guest', 'throttle:login'])
    ->post('/auth/login', [SessionController::class, 'store'])
    ->name('auth.login');
Route::middleware('auth')->post('/auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_READ])
    ->get('/workspaces/{workspace}/publishing', PublishingOperatorController::class)
    ->name('publishing.operator');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::JOURNEY_READ])
    ->get('/workspaces/{workspace}/journeys', [JourneyOperatorController::class, 'index'])
    ->name('journeys.operator');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::JOURNEY_CREATE])
    ->post('/workspaces/{workspace}/journeys', [JourneyOperatorController::class, 'create'])
    ->name('journeys.create');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::JOURNEY_CREATE])
    ->post('/workspaces/{workspace}/journeys/{journey}/draft', [JourneyOperatorController::class, 'saveDraft'])
    ->name('journeys.draft');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::JOURNEY_PUBLISH])
    ->post('/workspaces/{workspace}/journeys/{journey}/publish', [JourneyOperatorController::class, 'publish'])
    ->name('journeys.publish');
Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::JOURNEY_PUBLISH])
    ->post('/workspaces/{workspace}/journeys/{journey}/lifecycle/{action}', [JourneyOperatorController::class, 'lifecycle'])
    ->name('journeys.lifecycle');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_APPROVE])
    ->post('/workspaces/{workspace}/publishing/approvals/bulk', PublishingBulkApprovalController::class)
    ->name('publishing.approvals.bulk');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ])
    ->get('/workspaces/{workspace}/segments', [SegmentProposalController::class, 'index'])
    ->name('segments.operator');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ, 'throttle:30,1'])
    ->post('/workspaces/{workspace}/segments/preview', [SegmentProposalController::class, 'preview'])
    ->name('segments.preview');

Route::middleware([
    'auth',
    'tenant',
    'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::AI_EXECUTE,
])
    ->post('/workspaces/{workspace}/segments/proposals', [SegmentProposalController::class, 'propose'])
    ->name('segments.proposals');

Route::middleware([
    'auth',
    'tenant',
    'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::CONTACT_WRITE,
])
    ->post('/workspaces/{workspace}/segments/versions', [SegmentProposalController::class, 'store'])
    ->name('segments.versions.store');

Route::middleware([
    'auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::CONTACT_WRITE,
])->post('/workspaces/{workspace}/segments/{segment}/versions', [SegmentProposalController::class, 'revise'])
    ->name('segments.versions.revise');

Route::middleware([
    'auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::CONTACT_WRITE,
])->post('/workspaces/{workspace}/segments/{segment}/publish', [SegmentProposalController::class, 'publish'])
    ->name('segments.versions.publish');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::ANALYTICS_READ])
    ->group(function (): void {
        $controller = AnalyticsOperatorController::class;
        Route::get('/workspaces/{workspace}/analytics', [$controller, 'index'])->name('analytics.operator');
        Route::post('/workspaces/{workspace}/analytics/quality', [$controller, 'quality'])->middleware('throttle:30,1')->name('analytics.quality');
        Route::post('/workspaces/{workspace}/analytics/reports', [$controller, 'generate'])->middleware('throttle:30,1')->name('analytics.generate');
        Route::post('/workspaces/{workspace}/analytics/schedules', [$controller, 'schedule'])->name('analytics.schedules');
        Route::post('/workspaces/{workspace}/analytics/schedules/{schedule}/disable', [$controller, 'disable'])->name('analytics.schedule.disable');
        Route::post('/workspaces/{workspace}/analytics/anomaly', [$controller, 'anomaly'])->name('analytics.anomaly');
        Route::post('/workspaces/{workspace}/analytics/autonomy/preview', [$controller, 'autonomyPreview'])
            ->middleware(['workspace.permission:'.PermissionCatalog::AI_EXECUTE, 'throttle:30,1'])
            ->name('analytics.autonomy.preview');
        Route::post('/workspaces/{workspace}/analytics/explain', [$controller, 'explain'])
            ->middleware('workspace.permission:'.PermissionCatalog::AI_EXECUTE)->name('analytics.explain');
    });
