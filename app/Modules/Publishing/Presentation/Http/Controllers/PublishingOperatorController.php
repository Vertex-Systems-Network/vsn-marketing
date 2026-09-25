<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\PublishingOperatorReadModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PublishingOperatorController
{
    public function __invoke(Request $request, PublishingOperatorReadModel $readModel, WorkspaceAuthorizer $authorizer): Response
    {
        $context = $request->attributes->get('tenant_context');
        $actor = $request->user();

        if (! $context instanceof TenantContext || ! $actor instanceof User) {
            throw new AuthorizationException('Publishing operator tenant context is required.');
        }

        $props = $readModel->forWorkspace($context);
        $props['permissions'] = [
            'can_approve' => $authorizer->allows($actor, $context, PermissionCatalog::CAMPAIGN_APPROVE),
        ];
        $props['bulk_result'] = $request->session()->get('publishing_bulk_result');

        return Inertia::render('publishing/operator', $props);
    }
}
