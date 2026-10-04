<?php

namespace App\Modules\Providers\Domain\Messaging;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MessagingOperation
{
    /** @param array<string,mixed> $evidence */
    public function __construct(public string $id, public string $workspaceId, public string $channel, public string $providerKey, public string $idempotencyKey, public string $requestFingerprint, public MessagingOperationState $state, public ?string $providerOperationId, public bool $ambiguousOutcome, public array $evidence, public DateTimeImmutable $createdAt, public DateTimeImmutable $updatedAt)
    {
        foreach ([$id, $workspaceId, $channel, $providerKey, $idempotencyKey, $requestFingerprint] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Messaging operation identity fields must be non-blank.');
            }
        }
    }

    public function reconcile(MessagingProviderOutcome $outcome): self
    {
        if ($this->providerOperationId !== null && $this->providerOperationId !== $outcome->providerOperationId) {
            throw new InvalidArgumentException('Provider operation ID does not match the reserved messaging operation.');
        }
        if ($outcome->observedAt < $this->updatedAt) {
            return $this;
        }
        if ($this->state->isTerminal()) {
            if ($this->state === $outcome->state && $this->providerOperationId === $outcome->providerOperationId) {
                return $this;
            } throw new InvalidArgumentException('A terminal messaging operation cannot transition to another outcome.');
        }
        $next = $outcome->ambiguous ? MessagingOperationState::Ambiguous : $outcome->state;
        if ($outcome->observedAt == $this->updatedAt && $this->state !== MessagingOperationState::Reserved && $next !== $this->state) {
            throw new InvalidArgumentException('Conflicting messaging outcomes at the same observation time.');
        }
        if ($this->state === MessagingOperationState::Pending && $next === MessagingOperationState::Submitted) {
            return $this;
        }
        if ($this->state === MessagingOperationState::Ambiguous && ! $next->isTerminal()) {
            return $this;
        }

        return new self($this->id, $this->workspaceId, $this->channel, $this->providerKey, $this->idempotencyKey, $this->requestFingerprint, $next, $this->providerOperationId ?? $outcome->providerOperationId, $next === MessagingOperationState::Ambiguous, array_merge($this->evidence, ['last_reconciliation_source' => $outcome->source->value, 'last_observed_at' => $outcome->observedAt->format(DateTimeImmutable::ATOM), 'provider_evidence' => $outcome->evidence]), $this->createdAt, $outcome->observedAt);
    }
}
