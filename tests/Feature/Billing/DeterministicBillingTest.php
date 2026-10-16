<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Billing\DeterministicBilling;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function billingWorkspace(string $slug, string $customer): string
{
    $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    $id = (string) Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => $slug, 'slug' => $slug])->getKey();
    DB::table('workspace_subscriptions')->insert(['workspace_id' => $id, 'plan_key' => 'starter', 'status' => 'active',
        'provider_customer_id' => $customer, 'period_started_at' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function billingAdmin(string $name, string $workspaceId): User
{
    $user = User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, $workspaceId);
    $role = DB::table('workspace_roles')->where('workspace_id', $workspaceId)->where('key', 'billing')->value('id')
        ?? $roles->createRole($workspaceId, 'billing', 'Billing');
    $roles->grantPermission($role, PermissionCatalog::BILLING_MANAGE);
    $roles->assignRole($membership, $role);

    return $user;
}

function stripeSigned(array $event, int $timestamp, string $secret = 'whsec_test_only'): array
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);

    return [$payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret)];
}

it('meters idempotently, rejects stale, future, unknown and conflicting evidence and enforces plan limits', function (): void {
    $workspace = billingWorkspace('one', 'cus_one');
    $billing = app(DeterministicBilling::class);
    $now = time();

    expect($billing->meter($workspace, 'messages.sent', 9000, 'batch-1', $now - 60, $now))->toBeTrue();
    expect($billing->meter($workspace, 'messages.sent', 9000, 'batch-1', $now - 60, $now))->toBeFalse();
    expect(fn () => $billing->meter($workspace, 'messages.sent', 1, 'batch-1', $now - 60, $now))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->meter($workspace, 'messages.sent', 1, 'old', $now - 40 * 86400, $now))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->meter($workspace, 'messages.sent', 1, 'future', $now + 3600, $now))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->meter($workspace, 'free.money', 1, 'unknown', $now, $now))->toThrow(AuthorizationException::class);
    expect(DB::table('usage_meter_events')->count())->toBe(1);

    expect($billing->admits($workspace, 'messages.sent', 1000))->toBeTrue()
        ->and($billing->admits($workspace, 'messages.sent', 1001))->toBeFalse()
        ->and($billing->admits($workspace, 'unknown.metric', 1))->toBeFalse();
    DB::table('workspace_subscriptions')->where('workspace_id', $workspace)->update(['status' => 'past_due']);
    expect($billing->admits($workspace, 'messages.sent', 1))->toBeFalse();
});

it('keeps metering and entitlements tenant scoped', function (): void {
    $one = billingWorkspace('one', 'cus_one');
    $two = billingWorkspace('two', 'cus_two');
    $billing = app(DeterministicBilling::class);
    $now = time();
    $billing->meter($one, 'messages.sent', 10000, 'shared-key', $now, $now);
    expect($billing->meter($two, 'messages.sent', 5, 'shared-key', $now, $now))->toBeTrue();
    expect($billing->admits($one, 'messages.sent', 1))->toBeFalse()
        ->and($billing->admits($two, 'messages.sent', 9995))->toBeTrue();
    expect(fn () => $billing->meter((string) Str::uuid(), 'messages.sent', 1, 'k', $now, $now))->toThrow(AuthorizationException::class);
});

it('requires two distinct authorized humans for a plan change', function (): void {
    $workspace = billingWorkspace('one', 'cus_one');
    $other = billingWorkspace('two', 'cus_two');
    $requester = billingAdmin('Requester', $workspace);
    $approver = billingAdmin('Approver', $workspace);
    $foreign = billingAdmin('Foreign', $other);
    $billing = app(DeterministicBilling::class);

    expect(fn () => $billing->requestPlanChange($foreign, $workspace, 'growth'))->toThrow(AuthorizationException::class);
    $request = $billing->requestPlanChange($requester, $workspace, 'growth');
    expect(fn () => $billing->approvePlanChange($requester, $workspace, $request))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->approvePlanChange($foreign, $workspace, $request))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->approvePlanChange($foreign, $other, $request))->toThrow(AuthorizationException::class);
    expect(DB::table('workspace_subscriptions')->where('workspace_id', $workspace)->value('plan_key'))->toBe('starter');

    $billing->approvePlanChange($approver, $workspace, $request);
    expect(DB::table('workspace_subscriptions')->where('workspace_id', $workspace)->value('plan_key'))->toBe('growth');
    expect(fn () => $billing->approvePlanChange($approver, $workspace, $request))->toThrow(AuthorizationException::class);
    expect(fn () => $billing->requestPlanChange($requester, $workspace, 'enterprise-unlimited'))->toThrow(AuthorizationException::class);
});

it('keeps live charges disabled', function (): void {
    expect(fn () => app(DeterministicBilling::class)->createLiveCharge())->toThrow(AuthorizationException::class);
    expect(config('billing.mode'))->toBe('test');
});

it('verifies Stripe test webhooks and rejects replay, tampering, staleness, live mode and cross-tenant events', function (): void {
    config(['billing.webhook_secret' => 'whsec_test_only']);
    $one = billingWorkspace('one', 'cus_one');
    billingWorkspace('two', 'cus_two');
    $billing = app(DeterministicBilling::class);
    $now = time();
    $event = ['id' => 'evt_1', 'type' => 'invoice.payment_failed', 'livemode' => false,
        'data' => ['object' => ['customer' => 'cus_one', 'metadata' => ['workspace_id' => $one]]]];

    [$payload, $header] = stripeSigned($event, $now);
    expect($billing->ingestWebhook($payload, $header, $now))->toBe('applied');
    expect(DB::table('workspace_subscriptions')->where('workspace_id', $one)->value('status'))->toBe('past_due');
    expect($billing->ingestWebhook($payload, $header, $now))->toBe('duplicate');

    expect(fn () => $billing->ingestWebhook(str_replace('payment_failed', 'paid', $payload), $header, $now))->toThrow(AuthorizationException::class);
    [$p, $h] = stripeSigned([...$event, 'id' => 'evt_2'], $now, 'whsec_attacker');
    expect(fn () => $billing->ingestWebhook($p, $h, $now))->toThrow(AuthorizationException::class);
    [$p, $h] = stripeSigned([...$event, 'id' => 'evt_3'], $now - 600);
    expect(fn () => $billing->ingestWebhook($p, $h, $now))->toThrow(AuthorizationException::class);
    [$p, $h] = stripeSigned([...$event, 'id' => 'evt_4', 'livemode' => true], $now);
    expect(fn () => $billing->ingestWebhook($p, $h, $now))->toThrow(AuthorizationException::class);
    $crossTenant = $event;
    $crossTenant['id'] = 'evt_5';
    $crossTenant['data']['object']['customer'] = 'cus_two';
    [$p, $h] = stripeSigned($crossTenant, $now);
    expect(fn () => $billing->ingestWebhook($p, $h, $now))->toThrow(AuthorizationException::class);
    expect(DB::table('billing_webhook_events')->count())->toBe(1);
});

it('applies exactly once under repeated concurrent-style delivery of the same event', function (): void {
    config(['billing.webhook_secret' => 'whsec_test_only']);
    $one = billingWorkspace('one', 'cus_one');
    $billing = app(DeterministicBilling::class);
    $now = time();
    [$payload, $header] = stripeSigned(['id' => 'evt_c', 'type' => 'invoice.paid', 'livemode' => false,
        'data' => ['object' => ['customer' => 'cus_one']]], $now);
    $outcomes = array_map(fn (): string => $billing->ingestWebhook($payload, $header, $now), range(1, 5));
    expect(array_count_values($outcomes))->toBe(['applied' => 1, 'duplicate' => 4]);
    expect(DB::table('audit_events')->where('workspace_id', $one)->where('action', 'billing.webhook.applied')->count())->toBe(1);
});
