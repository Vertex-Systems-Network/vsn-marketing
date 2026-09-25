<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\CampaignApprovalRevocationService;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublishingApprovalRevocationController
{
    public function __invoke(
        Request $request,
        CampaignApprovalRevocationService $revocations,
    ): RedirectResponse {
        $context = $request->attributes->get('tenant_context');
        $actor = $request->user();

        if (! $context instanceof TenantContext || ! $actor instanceof User) {
            throw new AuthorizationException(
                'Publishing approval revocation tenant context is required.',
            );
        }

        $validated = $request->validate([
            'command_id' => ['required', 'uuid'],
            'campaign_id' => ['required', 'uuid'],
            'snapshot_id' => ['required', 'uuid'],
            'state_version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_hash' => ['nullable', 'string', 'size:64', 'regex:/\\A[a-f0-9]{64}\\z/'],
        ]);

        $sessionKey = 'publishing_approval_revoke_preflight.'.$validated['command_id'];

        if ($validated['confirmed']) {
            $submittedHash = $validated['confirmation_hash'] ?? null;
            $storedHash = $request->session()->get($sessionKey);

            if (
                ! is_string($submittedHash)
                || ! is_string($storedHash)
                || ! hash_equals($storedHash, $submittedHash)
            ) {
                throw ValidationException::withMessages([
                    'confirmed' => 'Approval revocation confirmation must match a current server-recorded preflight.',
                ]);
            }

            $result = $revocations->revoke(
                actor: $actor,
                context: $context,
                commandId: $validated['command_id'],
                campaignId: $validated['campaign_id'],
                snapshotId: $validated['snapshot_id'],
                expectedStateVersion: $validated['state_version'],
                reason: $validated['reason'],
                confirmationHash: $submittedHash,
                at: new DateTimeImmutable('now'),
            );

            $request->session()->forget($sessionKey);
        } else {
            $result = $revocations->preflight(
                actor: $actor,
                context: $context,
                commandId: $validated['command_id'],
                campaignId: $validated['campaign_id'],
                snapshotId: $validated['snapshot_id'],
                expectedStateVersion: $validated['state_version'],
                reason: $validated['reason'],
                at: new DateTimeImmutable('now'),
            );

            $request->session()->put(
                $sessionKey,
                $result['confirmation_hash'],
            );
        }

        return redirect()
            ->route('publishing.operator', ['workspace' => $context->workspaceId])
            ->with('publishing_revocation_result', $result);
    }
}
