<?php

use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Providers\Application\Messaging\ReserveOfflineMessagingIntent;
use App\Modules\Providers\Domain\Messaging\Contracts\MessagingOperationRepository;
use App\Modules\Providers\Domain\Messaging\MessagingChannel;
use App\Modules\Providers\Domain\Messaging\MessagingIntent;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingOperationState;
use App\Modules\Providers\Infrastructure\Messaging\OfflineMessagingAdapter;
use App\Modules\Providers\Infrastructure\Messaging\ResearchBackedMessagingAdapters;

function task0076Intent(OfflineMessagingAdapter $adapter, array $overrides = []): MessagingIntent
{
    $capability = $adapter->capability();
    $provider = $overrides['providerKey'] ?? $capability->providerKey;

    return new MessagingIntent(
        workspaceId: 'workspace-a',
        brandId: 'brand-a',
        accountWorkspaceId: $overrides['accountWorkspaceId'] ?? 'workspace-a',
        providerKey: $provider,
        channel: $overrides['channel'] ?? $capability->channel,
        recipientIdentityType: $overrides['recipientIdentityType'] ?? $capability->channel->recipientIdentity(),
        recipientReference: 'opaque-recipient-1',
        idempotencyKey: 'intent-1',
        channelPurposeAuthorized: $overrides['channelPurposeAuthorized'] ?? true,
        providerOptOutApplies: $overrides['providerOptOutApplies'] ?? false,
        accountApproved: $overrides['accountApproved'] ?? true,
        rateBudgetAvailable: $overrides['rateBudgetAvailable'] ?? true,
        eligibility: new SuppressionAwareEligibilityRequest(
            policyContext: new EligibilityContext(
                messagePurpose: MessageIntentType::Marketing,
                jurisdiction: 'GB',
                subscriberType: 'individual',
                solicitationBasis: 'direct_marketing',
                relationshipBasis: 'customer',
                policyBasis: PolicyBasisType::ExplicitConsent,
                policyBasisEvidencePresent: true,
                jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
                policyVersion: 'policy-1',
                policyEffectiveAt: new DateTimeImmutable('2026-10-01T00:00:00Z'),
                providerKey: $overrides['policyProviderKey'] ?? $provider,
                providerContextKnown: true,
                suppressionApplies: false,
                objectionApplies: false,
            ),
            canonicalSuppressionApplies: $overrides['canonicalSuppressionApplies'] ?? false,
            canonicalObjectionApplies: false,
        ),
    );
}

it('maps five distinct messaging channels and prepares only an offline intent', function () {
    $adapters = ResearchBackedMessagingAdapters::candidates();
    expect(array_map(fn (OfflineMessagingAdapter $adapter) => $adapter->capability()->channel, $adapters))
        ->toBe([MessagingChannel::Sms, MessagingChannel::WhatsApp, MessagingChannel::Rcs, MessagingChannel::Push, MessagingChannel::InApp]);

    foreach ($adapters as $adapter) {
        $intent = task0076Intent($adapter);
        $decision = $adapter->prepare($intent, $adapter->capability()->requiredScopes, new DateTimeImmutable('2026-10-05T00:00:00Z'));
        expect($decision->offlinePreparationAllowed)->toBeTrue()
            ->and($decision->liveDeliveryAllowed)->toBeFalse()
            ->and($adapter->dispatch($intent)->liveDeliveryAllowed)->toBeFalse();
    }
});

it('fails closed for cross-tenant, channel consent, provider opt-out, quota and canonical suppression', function () {
    $adapter = ResearchBackedMessagingAdapters::candidates()[1];
    foreach ([
        ['accountWorkspaceId' => 'workspace-b', 'reason' => 'account_workspace_mismatch'],
        ['channelPurposeAuthorized' => false, 'reason' => 'channel_purpose_unapproved'],
        ['providerOptOutApplies' => true, 'reason' => 'provider_opt_out'],
        ['rateBudgetAvailable' => false, 'reason' => 'rate_budget_exhausted'],
        ['canonicalSuppressionApplies' => true, 'reason' => 'canonical_eligibility_not_allow'],
        ['policyProviderKey' => 'wrong', 'reason' => 'policy_provider_mismatch'],
        ['recipientIdentityType' => 'email', 'reason' => 'recipient_identity_mismatch'],
    ] as $case) {
        $reason = $case['reason'];
        unset($case['reason']);
        $decision = $adapter->prepare(task0076Intent($adapter, $case), $adapter->capability()->requiredScopes, new DateTimeImmutable('2026-10-05T00:00:00Z'));
        expect($decision->offlinePreparationAllowed)->toBeFalse()->and($decision->reasons)->toContain($reason);
    }
});

it('refuses absent scopes and stale capability evidence without falling back across channels', function () {
    $adapter = ResearchBackedMessagingAdapters::candidates()[2];
    $intent = task0076Intent($adapter);

    foreach ([
        [[], new DateTimeImmutable('2026-10-05T00:00:00Z')],
        [$adapter->capability()->requiredScopes, new DateTimeImmutable('2026-11-05T00:00:00Z')],
    ] as [$scopes, $at]) {
        $decision = $adapter->prepare($intent, $scopes, $at);
        expect($decision->offlinePreparationAllowed)->toBeFalse()->and($decision->reasons)->toContain('capability_unavailable_or_stale');
    }

    $wrongChannel = task0076Intent($adapter, ['channel' => MessagingChannel::Sms]);
    expect($adapter->prepare($wrongChannel, $adapter->capability()->requiredScopes, new DateTimeImmutable('2026-10-05T00:00:00Z'))->offlinePreparationAllowed)->toBeFalse();
});

it('binds an offline reservation to brand recipient and payload without persisting them', function () {
    $adapter = ResearchBackedMessagingAdapters::candidates()[0];
    $intent = task0076Intent($adapter);
    $hash = hash('sha256', 'synthetic-payload');
    $repository = Mockery::mock(MessagingOperationRepository::class);
    $repository->shouldReceive('reserve')->once()->with('workspace-a', 'sms', $intent->providerKey, 'intent-1', Mockery::on(fn (string $digest): bool => $digest === hash('sha256', json_encode([
        $intent->workspaceId, $intent->brandId, $intent->accountWorkspaceId, $intent->providerKey,
        $intent->channel->value, $intent->recipientIdentityType, $intent->recipientReference, $hash,
    ], JSON_THROW_ON_ERROR))))->andReturn(new MessagingOperation('operation-1', 'workspace-a', 'sms', $intent->providerKey, 'intent-1', $hash, MessagingOperationState::Reserved, null, false, [], new DateTimeImmutable, new DateTimeImmutable));
    $service = new ReserveOfflineMessagingIntent($repository);
    expect($service->reserve($adapter, $intent, $adapter->capability()->requiredScopes, new DateTimeImmutable('2026-10-05Z'), $hash)->state)->toBe(MessagingOperationState::Reserved);
    Mockery::close();
});

it('rechecks suppression and rejects invalid payload digests before any durable reservation', function () {
    $repository = Mockery::mock(MessagingOperationRepository::class);
    $repository->shouldNotReceive('reserve');
    $service = new ReserveOfflineMessagingIntent($repository);
    $adapter = ResearchBackedMessagingAdapters::candidates()[0];
    foreach ([['canonicalSuppressionApplies' => true], ['channelPurposeAuthorized' => false], ['providerOptOutApplies' => true]] as $overrides) {
        expect(fn () => $service->reserve($adapter, task0076Intent($adapter, $overrides), $adapter->capability()->requiredScopes, new DateTimeImmutable('2026-10-05Z'), hash('sha256', 'payload')))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $service->reserve($adapter, task0076Intent($adapter), [], new DateTimeImmutable('2026-10-05Z'), 'raw content'))->toThrow(InvalidArgumentException::class);
    Mockery::close();
});
