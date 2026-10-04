<?php

use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingOperationState;
use App\Modules\Providers\Domain\Messaging\MessagingProviderOutcome;

function task0076Operation(): MessagingOperation
{
    $now = new DateTimeImmutable('2026-10-04T10:00:00+00:00');

    return new MessagingOperation(
        id: 'operation-1',
        workspaceId: 'workspace-1',
        channel: 'sms',
        providerKey: 'azure-communication-services',
        idempotencyKey: 'intent-1',
        requestFingerprint: hash('sha256', 'request-1'),
        state: MessagingOperationState::Reserved,
        providerOperationId: null,
        ambiguousOutcome: false,
        evidence: ['reservation' => 'durable'],
        createdAt: $now,
        updatedAt: $now,
    );
}

it('reconciles a provider outcome while preserving the provider operation identity', function (): void {
    $operation = task0076Operation();
    $observedAt = new DateTimeImmutable('2026-10-04T10:01:00+00:00');

    $resolved = $operation->reconcile(new MessagingProviderOutcome(
        providerOperationId: 'provider-1',
        state: MessagingOperationState::Succeeded,
        source: ReconciliationSource::Webhook,
        observedAt: $observedAt,
        evidence: ['provider_status' => 'delivered'],
    ));

    expect($resolved->state)->toBe(MessagingOperationState::Succeeded)
        ->and($resolved->providerOperationId)->toBe('provider-1')
        ->and($resolved->evidence['last_reconciliation_source'])->toBe('webhook');
});

it('holds ambiguous outcomes instead of treating them as successful delivery', function (): void {
    $resolved = task0076Operation()->reconcile(new MessagingProviderOutcome(
        providerOperationId: 'provider-2',
        state: MessagingOperationState::Succeeded,
        source: ReconciliationSource::Polling,
        observedAt: new DateTimeImmutable('2026-10-04T10:02:00+00:00'),
        ambiguous: true,
    ));

    expect($resolved->state)->toBe(MessagingOperationState::Ambiguous)
        ->and($resolved->ambiguousOutcome)->toBeTrue();
});

it('rejects a provider identity mismatch and terminal state mutation', function (): void {
    $operation = task0076Operation()->reconcile(new MessagingProviderOutcome(
        providerOperationId: 'provider-1',
        state: MessagingOperationState::Succeeded,
        source: ReconciliationSource::Webhook,
        observedAt: new DateTimeImmutable('2026-10-04T10:01:00+00:00'),
    ));

    expect(fn () => $operation->reconcile(new MessagingProviderOutcome(
        providerOperationId: 'provider-2',
        state: MessagingOperationState::Failed,
        source: ReconciliationSource::Polling,
        observedAt: new DateTimeImmutable('2026-10-04T10:03:00+00:00'),
    )))->toThrow(InvalidArgumentException::class);
});

it('keeps pending progress monotonic and refuses same-time conflicting observations', function () {
    $at = new DateTimeImmutable('2026-10-04T10:01:00Z');
    $op = task0076Operation()->reconcile(new MessagingProviderOutcome('provider-1', MessagingOperationState::Pending, ReconciliationSource::Polling, $at));
    expect($op->reconcile(new MessagingProviderOutcome('provider-1', MessagingOperationState::Submitted, ReconciliationSource::Polling, $at->modify('+1 second'))))->toBe($op);
    expect(fn () => $op->reconcile(new MessagingProviderOutcome('provider-1', MessagingOperationState::Failed, ReconciliationSource::Webhook, $at)))->toThrow(InvalidArgumentException::class);
});

it('refuses raw provider payloads and credential text in durable evidence', function () {
    expect(fn () => new MessagingProviderOutcome('provider-1', MessagingOperationState::Pending, ReconciliationSource::Polling, new DateTimeImmutable, ['token' => 'secret']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MessagingProviderOutcome('provider-1', MessagingOperationState::Pending, ReconciliationSource::Polling, new DateTimeImmutable, ['provider_status' => 'recipient@example.test']))->toThrow(InvalidArgumentException::class);
});
