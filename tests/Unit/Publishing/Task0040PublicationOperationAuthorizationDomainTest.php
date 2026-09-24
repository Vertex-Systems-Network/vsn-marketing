<?php

use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use App\Modules\Publishing\Domain\Publication\PublicationOperationAuthorization;
use InvalidArgumentException;

it('maps publication operations to the provider capability taxonomy without inventing a retry capability', function () {
    expect(PublicationOperation::Retry->providerCapabilityOperation())->toBe('publication.create')
        ->and(PublicationOperation::Edit->providerCapabilityOperation())->toBe('publication.update')
        ->and(PublicationOperation::Delete->providerCapabilityOperation())->toBe('publication.delete');
});

it('hashes immutable operation authorization provenance deterministically', function () {
    $first = new PublicationOperationAuthorization(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        operation: PublicationOperation::Edit,
        providerCapabilityOperation: 'publication.update',
        providerId: 'provider-1',
        providerConnectionId: 'connection-1',
        originalCapabilityEvidenceId: 'capability-create-1',
        currentCapabilityEvidenceId: 'capability-update-1',
        currentCapabilitySourceVersion: '2026-09-update',
        connectionSourceVersion: '2026-09',
        attemptHash: str_repeat('a', 64),
        projectionObservationHash: str_repeat('b', 64),
        projectionVersion: 2,
        providerOperationId: 'provider-operation-1',
        authorizedAt: new \DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $second = new PublicationOperationAuthorization(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        operation: PublicationOperation::Edit,
        providerCapabilityOperation: 'publication.update',
        providerId: 'provider-1',
        providerConnectionId: 'connection-1',
        originalCapabilityEvidenceId: 'capability-create-1',
        currentCapabilityEvidenceId: 'capability-update-1',
        currentCapabilitySourceVersion: '2026-09-update',
        connectionSourceVersion: '2026-09',
        attemptHash: str_repeat('a', 64),
        projectionObservationHash: str_repeat('b', 64),
        projectionVersion: 2,
        providerOperationId: 'provider-operation-1',
        authorizedAt: new \DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    expect($first->authorizationHash)->toBe($second->authorizationHash)
        ->and(strlen($first->authorizationHash))->toBe(64);
});

it('rejects mismatched requested and provider capability operations', function () {
    expect(fn () => new PublicationOperationAuthorization(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        operation: PublicationOperation::Delete,
        providerCapabilityOperation: 'publication.update',
        providerId: 'provider-1',
        providerConnectionId: 'connection-1',
        originalCapabilityEvidenceId: 'capability-create-1',
        currentCapabilityEvidenceId: 'capability-delete-1',
        currentCapabilitySourceVersion: '2026-09-delete',
        connectionSourceVersion: '2026-09',
        attemptHash: str_repeat('a', 64),
        projectionObservationHash: str_repeat('b', 64),
        projectionVersion: 1,
        providerOperationId: 'provider-operation-1',
        authorizedAt: new \DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'does not match');
});
