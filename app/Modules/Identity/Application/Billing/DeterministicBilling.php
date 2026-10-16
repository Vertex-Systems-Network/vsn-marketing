<?php

namespace App\Modules\Identity\Application\Billing;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic plans, metering and entitlements. No model or automated actor
 * holds financial authority: plan changes need two distinct humans with
 * billing.manage, and live charges are hard-disabled (ADR-0006).
 */
final readonly class DeterministicBilling
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private AuditRecorder $audit) {}

    /** Idempotent usage record. Returns false for a duplicate key; throws on stale/future/unknown evidence. */
    public function meter(string $workspaceId, string $metric, int $quantity, string $idempotencyKey, int $occurredAt, int $now): bool
    {
        $maxAge = (int) config('billing.max_evidence_age_days') * 86400;
        if ($quantity < 1 || $idempotencyKey === '' || strlen($idempotencyKey) > 191
            || ! $this->knownMetric($metric) || $occurredAt > $now + 60 || $occurredAt < $now - $maxAge
            || ! DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->exists()) {
            throw new AuthorizationException('Usage evidence rejected.');
        }
        $inserted = DB::table('usage_meter_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'metric' => $metric, 'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey, 'occurred_at' => date('Y-m-d H:i:s', $occurredAt), 'created_at' => now(),
        ]);
        if ($inserted !== 1) {
            $existing = DB::table('usage_meter_events')->where('workspace_id', $workspaceId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null || $existing->metric !== $metric || (int) $existing->quantity !== $quantity) {
                throw new AuthorizationException('Conflicting duplicate usage evidence.');
            }

            return false;
        }

        return true;
    }

    /** Fail-closed entitlement check for consuming $quantity more units in the current period. */
    public function admits(string $workspaceId, string $metric, int $quantity): bool
    {
        $subscription = DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->first();
        $limits = $subscription === null ? null : config('billing.plans.'.$subscription->plan_key);
        if ($subscription === null || $subscription->status !== 'active' || ! is_array($limits)
            || ! is_int($limits[$metric] ?? null) || $quantity < 1) {
            return false;
        }
        $used = (int) DB::table('usage_meter_events')->where('workspace_id', $workspaceId)->where('metric', $metric)
            ->where('occurred_at', '>=', $subscription->period_started_at)->sum('quantity');

        return $used + $quantity <= $limits[$metric];
    }

    public function requestPlanChange(User $requester, string $workspaceId, string $toPlan): string
    {
        $this->requireBilling($requester, $workspaceId);
        $current = DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->value('plan_key');
        if (! is_string($current) || ! is_array(config('billing.plans.'.$toPlan)) || $current === $toPlan) {
            throw new AuthorizationException('Plan change rejected.');
        }
        $id = (string) Str::uuid();
        DB::table('plan_change_requests')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'from_plan' => $current,
            'to_plan' => $toPlan, 'requested_by' => $requester->getKey(), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($workspaceId, 'billing.plan_change.requested', ['request_id' => $id, 'to' => $toPlan],
            actorId: (string) $requester->getKey());

        return $id;
    }

    public function approvePlanChange(User $approver, string $workspaceId, string $requestId): void
    {
        $this->requireBilling($approver, $workspaceId);
        DB::transaction(function () use ($approver, $workspaceId, $requestId): void {
            $request = DB::table('plan_change_requests')->where('id', $requestId)->where('workspace_id', $workspaceId)
                ->lockForUpdate()->first();
            $current = DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->lockForUpdate()->value('plan_key');
            if ($request === null || $request->status !== 'pending' || $request->requested_by === $approver->getKey()
                || $current !== $request->from_plan) {
                throw new AuthorizationException('Plan change approval denied.');
            }
            DB::table('plan_change_requests')->where('id', $requestId)
                ->update(['status' => 'approved', 'approved_by' => $approver->getKey(), 'updated_at' => now()]);
            DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)
                ->update(['plan_key' => $request->to_plan, 'updated_at' => now()]);
        });
        $this->audit->record($workspaceId, 'billing.plan_change.approved', ['request_id' => $requestId],
            actorId: (string) $approver->getKey());
    }

    /** Live charges are disabled regardless of configuration. */
    public function createLiveCharge(): never
    {
        throw new AuthorizationException('Live charges are disabled (ADR-0006: Stripe test mode only).');
    }

    /** Verifies and applies a Stripe test-mode webhook. Returns the outcome string. */
    public function ingestWebhook(string $payload, string $signatureHeader, int $now): string
    {
        $secret = (string) config('billing.webhook_secret');
        if ($secret === '' || ! $this->validSignature($payload, $signatureHeader, $secret, $now)) {
            throw new AuthorizationException('Webhook signature rejected.');
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new AuthorizationException('Webhook payload rejected.');
        }
        if (($event['livemode'] ?? true) !== false) {
            throw new AuthorizationException('Live-mode billing events are not accepted.');
        }
        $object = $event['data']['object'] ?? [];
        $customer = is_array($object) && is_string($object['customer'] ?? null) ? $object['customer'] : null;
        $workspace = $customer === null ? null
            : DB::table('workspace_subscriptions')->where('provider_customer_id', $customer)->value('workspace_id');
        $claimed = $object['metadata']['workspace_id'] ?? null;
        if (! is_string($workspace) || ($claimed !== null && $claimed !== $workspace)) {
            throw new AuthorizationException('Webhook tenant mismatch.');
        }

        return DB::transaction(function () use ($event, $workspace): string {
            $inserted = DB::table('billing_webhook_events')->insertOrIgnore([
                'provider_event_id' => $event['id'], 'workspace_id' => $workspace, 'type' => $event['type'],
                'outcome' => 'applied', 'created_at' => now(),
            ]);
            if ($inserted !== 1) {
                return 'duplicate';
            }
            $status = match ($event['type']) {
                'customer.subscription.deleted' => 'canceled',
                'invoice.payment_failed' => 'past_due',
                'invoice.paid' => 'active',
                default => null,
            };
            if ($status !== null) {
                DB::table('workspace_subscriptions')->where('workspace_id', $workspace)
                    ->update(['status' => $status, 'updated_at' => now()]);
            }
            $this->audit->record($workspace, 'billing.webhook.applied', ['event_id' => $event['id'], 'type' => $event['type']]);

            return 'applied';
        });
    }

    private function validSignature(string $payload, string $header, string $secret, int $now): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs($now - $timestamp) > (int) config('billing.webhook_tolerance_seconds')) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function knownMetric(string $metric): bool
    {
        foreach ((array) config('billing.plans') as $limits) {
            if (is_array($limits) && array_key_exists($metric, $limits)) {
                return true;
            }
        }

        return false;
    }

    private function requireBilling(User $user, string $workspaceId): void
    {
        $organization = DB::table('workspaces')->where('id', $workspaceId)->value('organization_id');
        if (! is_string($organization) || ! $this->authorizer->allows($user,
            new TenantContext($organization, $workspaceId, null, (string) $user->getKey()), PermissionCatalog::BILLING_MANAGE)) {
            throw new AuthorizationException('Billing management denied.');
        }
    }
}
