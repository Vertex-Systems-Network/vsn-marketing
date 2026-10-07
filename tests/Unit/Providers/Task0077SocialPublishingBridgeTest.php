<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Social\SocialOperation;
use App\Modules\Providers\Domain\Social\SocialPlatform;
use App\Modules\Providers\Domain\Social\SocialPublicationIntent;
use App\Modules\Providers\Infrastructure\Social\OfflineSocialPublishingAdapter;
use App\Modules\Providers\Infrastructure\Social\ResearchBackedSocialCapabilities;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;

it('binds researched social capability to canonical publication approval and idempotency evidence without live dispatch', function () {
    $capability = array_values(array_filter(
        ResearchBackedSocialCapabilities::candidates(),
        fn ($candidate) => $candidate->platform === SocialPlatform::LinkedIn
            && $candidate->operation === SocialOperation::Create
            && $candidate->requiredScopes === ['w_member_social'],
    ))[0];

    $attempt = PublicationAttempt::prepare(
        'attempt-1',
        'workspace-a',
        'intent-1',
        'campaign-1',
        'snapshot-1',
        'target-1',
        hash('sha256', 'target'),
        'linkedin',
        'connection-1',
        'capability-1',
        'linkedin-posts',
        new DateTimeImmutable('2026-10-07T10:00:00Z'),
    );
    $intent = new SocialPublicationIntent(
        $attempt->workspaceId,
        $attempt->workspaceId,
        SocialPlatform::LinkedIn,
        SocialOperation::Create,
        $capability->providerKey,
        $attempt->id,
        $attempt->idempotencyKey,
        true,
        true,
    );

    $decision = (new OfflineSocialPublishingAdapter($capability))->prepare(
        $intent,
        $capability->requiredScopes,
        $capability->requiredRoles,
        new DateTimeImmutable('2026-10-10T00:00:00Z'),
    );

    expect($decision->offlinePreparationAllowed)->toBeTrue()
        ->and($decision->livePublicationAllowed)->toBeFalse()
        ->and($decision->reasons)->toBe([]);
});

it('fails closed for tenant drift approval revocation quota exhaustion scope drift and ambiguous outcomes', function () {
    $capability = ResearchBackedSocialCapabilities::candidates()[0];
    $adapter = new OfflineSocialPublishingAdapter($capability);
    $base = [
        'workspaceId' => 'workspace-a',
        'accountWorkspaceId' => 'workspace-a',
        'platform' => $capability->platform,
        'operation' => $capability->operation,
        'providerKey' => $capability->providerKey,
        'publicationAttemptId' => 'attempt-1',
        'idempotencyKey' => hash('sha256', 'attempt-1'),
        'approvalValid' => true,
        'rateBudgetAvailable' => true,
    ];

    foreach ([
        ['accountWorkspaceId' => 'workspace-b', 'reason' => 'account_workspace_mismatch'],
        ['approvalValid' => false, 'reason' => 'canonical_approval_invalid'],
        ['rateBudgetAvailable' => false, 'reason' => 'rate_budget_exhausted'],
    ] as $case) {
        $reason = $case['reason'];
        unset($case['reason']);
        $intent = new SocialPublicationIntent(...array_values(array_replace($base, $case)));
        expect($adapter->prepare($intent, $capability->requiredScopes, $capability->requiredRoles, new DateTimeImmutable('2026-10-10T00:00:00Z'))->reasons)
            ->toContain($reason);
    }

    $intent = new SocialPublicationIntent(...array_values($base));
    expect($adapter->prepare($intent, [], [], new DateTimeImmutable('2026-10-10T00:00:00Z'))->offlinePreparationAllowed)->toBeFalse()
        ->and($adapter->normalizeProviderOutcome(ProviderOperationStatus::Succeeded, true))->toBe(ProviderOperationStatus::Unknown)
        ->and($adapter->normalizeProviderOutcome(ProviderOperationStatus::Succeeded, false))->toBe(ProviderOperationStatus::Succeeded);
});
