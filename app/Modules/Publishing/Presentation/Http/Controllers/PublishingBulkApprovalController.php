<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\CampaignBulkApprovalService;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PublishingBulkApprovalController
{
    public function __invoke(Request $request, CampaignBulkApprovalService $bulk): RedirectResponse
    {
        $context = $request->attributes->get('tenant_context');
        $actor = $request->user();

        if (! $context instanceof TenantContext || ! $actor instanceof User) {
            throw new AuthorizationException('Publishing approval tenant context is required.');
        }

        $validated = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'operation' => ['required', Rule::in(['approve', 'reject'])],
            'confirmed' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:operation,reject', 'min:3'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.campaign_id' => ['required', 'uuid', 'distinct'],
            'items.*.snapshot_id' => ['required', 'uuid'],
            'items.*.state_version' => ['required', 'integer', 'min:1'],
        ]);

        $preflightFingerprint = hash('sha256', json_encode([
            'workspace_id' => $context->workspaceId,
            'actor_id' => $context->actorId,
            'batch_id' => $validated['batch_id'],
            'operation' => $validated['operation'],
            'reason' => $validated['reason'] ?? null,
            'items' => $validated['items'],
        ], JSON_THROW_ON_ERROR));
        $preflightKey = 'publishing_bulk_preflight.'.$validated['batch_id'];

        if ($validated['confirmed']) {
            $storedFingerprint = $request->session()->get($preflightKey);
            if (! is_string($storedFingerprint) || ! hash_equals($storedFingerprint, $preflightFingerprint)) {
                throw ValidationException::withMessages([
                    'confirmed' => 'Bulk approval confirmation must match a current server-recorded preflight.',
                ]);
            }
        }

        $result = $bulk->handle(
            actor: $actor,
            context: $context,
            operation: $validated['operation'],
            items: $validated['items'],
            confirmed: $validated['confirmed'],
            batchId: $validated['batch_id'],
            reason: $validated['reason'] ?? null,
            at: new DateTimeImmutable('now'),
        );

        if (! $validated['confirmed']) {
            $request->session()->put($preflightKey, $preflightFingerprint);
        }

        return redirect()
            ->route('publishing.operator', ['workspace' => $context->workspaceId])
            ->with('publishing_bulk_result', $result);
    }
}
