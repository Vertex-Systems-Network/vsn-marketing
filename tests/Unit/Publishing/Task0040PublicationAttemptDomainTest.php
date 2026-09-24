<?php

use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use DateTimeImmutable;
use InvalidArgumentException;

function task0040DomainAttempt(): PublicationAttempt
{
    return PublicationAttempt::prepare(
        id: 'attempt-1',
        workspaceId: 'workspace-1',
        executionIntentId: 'intent-1',
        campaignId: 'campaign-1',
        snapshotId: 'snapshot-1',
        targetId: 'target-1',
        targetHash: str_repeat('a', 64),
        channel: 'social',
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: 'capability-1',
        providerId: 'provider-1',
        createdAt: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
}

it('derives stable publication create idempotency from the exact execution intent and target', function () {
    $first = task0040DomainAttempt();
    $second = PublicationAttempt::prepare(
        id: 'attempt-2',
        workspaceId: $first->workspaceId,
        executionIntentId: $first->executionIntentId,
        campaignId: $first->campaignId,
        snapshotId: $first->snapshotId,
        targetId: $first->targetId,
        targetHash: $first->targetHash,
        channel: $first->channel,
        providerConnectionId: $first->providerConnectionId,
        capabilityEvidenceId: $first->capabilityEvidenceId,
        providerId: $first->providerId,
        createdAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );

    expect($second->idempotencyKey)->toBe($first->idempotencyKey)
        ->and($second->attemptHash)->toBe($first->attemptHash)
        ->and($first->state)->toBe(PublicationAttemptState::Prepared)
        ->and($first->stateVersion)->toBe(1);
});

it('enforces the bounded publication attempt state machine', function () {
    $prepared = task0040DomainAttempt();
    $dispatching = $prepared->transitionTo(
        PublicationAttemptState::Dispatching,
        new DateTimeImmutable('2026-07-15T13:30:21+00:00'),
    );
    $published = $dispatching->transitionTo(
        PublicationAttemptState::Published,
        new DateTimeImmutable('2026-07-15T13:30:22+00:00'),
    );

    expect($dispatching->stateVersion)->toBe(2)
        ->and($published->stateVersion)->toBe(3)
        ->and($published->attemptHash)->toBe($prepared->attemptHash);

    expect(fn () => $published->transitionTo(
        PublicationAttemptState::Dispatching,
        new DateTimeImmutable('2026-07-15T13:30:23+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot transition');
});
