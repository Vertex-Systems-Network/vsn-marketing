<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Inertia\Inertia;
use Inertia\Response;

final readonly class EnterpriseOverviewController
{
    public function __construct(private TenantContextStore $tenant) {}

    public function __invoke(): Response
    {
        $context = $this->tenant->require();

        return Inertia::render('enterprise/overview', [
            'workspace_id' => $context->workspaceId,
            'audit_export_url' => route('workspace.audit.export', ['workspace' => $context->workspaceId]),
            'capabilities' => [
                ['name' => 'Audit metadata export', 'status' => 'Available to authorized workspace roles'],
                ['name' => 'Federated sign-in', 'status' => 'Not configured'],
                ['name' => 'SCIM deactivation', 'status' => config('enterprise.scim_deactivation_enabled')
                    ? 'Limited adapter enabled; provider compatibility unverified' : 'Disabled until integration approval'],
                ['name' => 'Residency and subject requests', 'status' => 'Manual review only'],
                ['name' => 'Billing', 'status' => 'Offline metering only; live charges unavailable'],
                ['name' => 'Recovery', 'status' => 'Synthetic offline exercise only'],
            ],
        ]);
    }
}
