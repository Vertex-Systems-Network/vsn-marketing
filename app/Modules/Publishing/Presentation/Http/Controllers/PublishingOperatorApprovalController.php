<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\PublishingOperatorActionService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PublishingOperatorApprovalController
{
    public function approve(Request $request, PublishingOperatorActionService $actions): RedirectResponse
    {
        return $this->apply($request, $actions, 'approve');
    }

    public function reject(Request $request, PublishingOperatorActionService $actions): RedirectResponse
    {
        return $this->apply($request, $actions, 'reject');
    }

    public function revoke(Request $request, PublishingOperatorActionService $actions): RedirectResponse
    {
        return $this->apply($request, $actions, 'revoke');
    }

    private function apply(
        Request $request,
        PublishingOperatorActionService $actions,
        string $action,
    ): RedirectResponse {
        $context = $request->attributes->get('tenant_context');
        $actor = $request->user();

        if (! $context instanceof TenantContext) {
            throw new AuthorizationException('Publishing operator tenant context is required.');
        }

        if (! $actor instanceof User) {
            throw new AuthenticationException('Publishing operator authentication is required.');
        }

        $payload = $request->validate([
            'snapshot_id' => ['required', 'uuid'],
            'state_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $campaignId = (string) $request->route('campaign');
        $snapshotId = (string) $payload['snapshot_id'];
        $stateVersion = (int) $payload['state_version'];
        $reason = isset($payload['reason']) && trim((string) $payload['reason']) !== ''
            ? trim((string) $payload['reason'])
            : null;
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            match ($action) {
                'approve' => $actions->approve(
                    $actor,
                    $context,
                    $campaignId,
                    $snapshotId,
                    $stateVersion,
                    $reason,
                    $at,
                ),
                'reject' => $actions->reject(
                    $actor,
                    $context,
                    $campaignId,
                    $snapshotId,
                    $stateVersion,
                    $reason,
                    $at,
                ),
                'revoke' => $actions->revoke(
                    $actor,
                    $context,
                    $campaignId,
                    $snapshotId,
                    $stateVersion,
                    $reason,
                    $at,
                ),
                default => throw new InvalidArgumentException('Unsupported approval action.'),
            };
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'approval' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('publishing.operator', ['workspace' => $context->workspaceId])
            ->with('status', 'Campaign approval action recorded.');
    }
}
