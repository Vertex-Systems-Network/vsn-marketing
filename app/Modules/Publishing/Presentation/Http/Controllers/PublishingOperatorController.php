<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\PublishingOperatorReadModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PublishingOperatorController
{
    public function __invoke(Request $request, PublishingOperatorReadModel $readModel): Response
    {
        $context = $request->attributes->get('tenant_context');

        if (! $context instanceof TenantContext) {
            throw new AuthorizationException('Publishing operator tenant context is required.');
        }

        return Inertia::render('publishing/operator', $readModel->forWorkspace($context));
    }
}
