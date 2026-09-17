<?php

use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationOutcome;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationRequest;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SynchronizeSuppression;
use App\Modules\Providers\Domain\Feedback\EvaluateProviderFeedback;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackClassification;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackDisposition;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackEvidence;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackType;

function task0027Feedback(
    ProviderFeedbackType $type = ProviderFeedbackType::Complaint,
    ProviderFeedbackClassification $classification = ProviderFeedbackClassification::Complaint,
    bool $trusted = true,
    ?DateTimeImmutable $freshUntil = null,
): ProviderFeedbackEvidence {
    return new ProviderFeedbackEvidence(
        id: 'feedback-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        providerKey: 'provider-a',
        type: $type,
        classification: $classification,
        replayKey: 'provider-event-1',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: $freshUntil,
        trustedSource: $trusted,
        redactedEvidence: ['event_class' => $classification->value],
    );
}

it('normalizes trusted complaint unsubscribe and permanent bounce into suppression dispositions', function () {
    $evaluator = new EvaluateProviderFeedback;
    $now = new DateTimeImmutable('2026-09-17T00:01:00+00:00');

    expect($evaluator->evaluate(task0027Feedback(), $now))->toBe(ProviderFeedbackDisposition::Suppress)
        ->and($evaluator->evaluate(task0027Feedback(
            type: ProviderFeedbackType::Unsubscribe,
            classification: ProviderFeedbackClassification::AcceptedUnsubscribe,
        ), $now))->toBe(ProviderFeedbackDisposition::Suppress)
        ->and($evaluator->evaluate(task0027Feedback(
            type: ProviderFeedbackType::Bounce,
            classification: ProviderFeedbackClassification::PermanentBounce,
        ), $now))->toBe(ProviderFeedbackDisposition::Suppress);
});

it('keeps transient bounce in review and rejects stale or untrusted evidence', function () {
    $evaluator = new EvaluateProviderFeedback;
    $now = new DateTimeImmutable('2026-09-17T00:02:00+00:00');

    expect($evaluator->evaluate(task0027Feedback(
        type: ProviderFeedbackType::Bounce,
        classification: ProviderFeedbackClassification::TransientBounce,
    ), $now))->toBe(ProviderFeedbackDisposition::Review)
        ->and($evaluator->evaluate(task0027Feedback(trusted: false), $now))->toBe(ProviderFeedbackDisposition::Reject)
        ->and($evaluator->evaluate(task0027Feedback(
            freshUntil: new DateTimeImmutable('2026-09-17T00:01:00+00:00'),
        ), $now))->toBe(ProviderFeedbackDisposition::Reject);
});

it('rejects contradictory feedback classification and direct pii evidence', function () {
    expect(fn () => task0027Feedback(
        type: ProviderFeedbackType::Complaint,
        classification: ProviderFeedbackClassification::PermanentBounce,
    ))->toThrow(InvalidArgumentException::class, 'classification');

    expect(fn () => new ProviderFeedbackEvidence(
        id: 'feedback-2',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        providerKey: 'provider-a',
        type: ProviderFeedbackType::Complaint,
        classification: ProviderFeedbackClassification::Complaint,
        replayKey: 'provider-event-2',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        trustedSource: true,
        redactedEvidence: ['recipient_email' => 'person@example.com'],
    ))->toThrow(InvalidArgumentException::class, 'secret or direct-PII');
});

it('keeps internal suppression active for every provider reconciliation outcome', function () {
    $handler = new SynchronizeSuppression;

    foreach (SuppressionSynchronizationOutcome::cases() as $outcome) {
        $result = $handler->handle(new SuppressionSynchronizationRequest(
            operationKey: 'sync-'.$outcome->value,
            workspaceId: 'workspace-1',
            suppressionRecordId: 'suppression-1',
            providerKey: 'provider-a',
            providerOutcome: $outcome,
            observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
            providerReference: 'provider-ref',
            publicEvidence: ['outcome' => $outcome->value],
        ));

        expect($result->internalSuppressionActive)->toBeTrue()
            ->and($result->restoresEligibility)->toBeFalse()
            ->and($result->reconciliationRequired)->toBe($outcome !== SuppressionSynchronizationOutcome::Confirmed);
    }
});

it('returns exact replay and rejects contradictory reuse of a synchronization operation key', function () {
    $handler = new SynchronizeSuppression;
    $request = new SuppressionSynchronizationRequest(
        operationKey: 'sync-1',
        workspaceId: 'workspace-1',
        suppressionRecordId: 'suppression-1',
        providerKey: 'provider-a',
        providerOutcome: SuppressionSynchronizationOutcome::Timeout,
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
    );
    $first = $handler->handle($request);

    expect($handler->handle($request, $first))->toBe($first);

    $contradiction = new SuppressionSynchronizationRequest(
        operationKey: 'sync-1',
        workspaceId: 'workspace-1',
        suppressionRecordId: 'suppression-1',
        providerKey: 'provider-a',
        providerOutcome: SuppressionSynchronizationOutcome::Confirmed,
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
    );

    expect(fn () => $handler->handle($contradiction, $first))
        ->toThrow(InvalidArgumentException::class, 'different replay outcome');
});
