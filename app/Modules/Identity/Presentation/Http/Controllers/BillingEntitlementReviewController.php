<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Billing\ConfigureMeterEntitlement;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class BillingEntitlementReviewController
{
    public function __construct(private ConfigureMeterEntitlement $service, private TenantContextStore $tenant) {}

    public function propose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meter' => ['required', 'string', 'max:80'],
            'limit_units' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'],
        ]);

        return response()->json(['proposal_id' => $this->service->propose(
            $this->user($request), $this->tenant->require(), $data['meter'],
            (int) $data['limit_units'], (bool) $data['enabled'],
        )], 201);
    }

    public function approve(Request $request, string $workspace, string $proposal): Response
    {
        $this->service->approve($this->user($request), $this->tenant->require(), $proposal);

        return response()->noContent();
    }

    public function apply(Request $request, string $workspace, string $proposal): Response
    {
        $this->service->apply($this->user($request), $this->tenant->require(), $proposal);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
