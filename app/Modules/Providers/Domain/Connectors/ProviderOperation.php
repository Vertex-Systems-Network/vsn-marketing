<?php

namespace App\Modules\Providers\Domain\Connectors;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProviderOperation
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $operation,
        public string $idempotencyKey,
        public ProviderOperationStatus $status,
        public DateTimeImmutable $submittedAt,
        public ?string $providerOperationId = null,
        public ?DateTimeImmutable $observedAt = null,
        public array $evidence = [],
    ) {}

    public function withObservation(ProviderOperationObservation $observation): self
    {
        if ($this->providerOperationId !== null && $this->providerOperationId !== $observation->providerOperationId) {
            throw new InvalidArgumentException('Provider operation observation does not match the canonical provider operation ID.');
        }

        if ($this->status->isTerminal()) {
            if ($this->status === $observation->status) {
                return $this;
            }

            throw new InvalidArgumentException('A terminal provider operation cannot be reconciled to a different terminal or non-terminal state.');
        }

        if (! $this->status->canAdvanceTo($observation->status)) {
            return $this;
        }

        return new self(
            operation: $this->operation,
            idempotencyKey: $this->idempotencyKey,
            status: $observation->status,
            submittedAt: $this->submittedAt,
            providerOperationId: $this->providerOperationId ?? $observation->providerOperationId,
            observedAt: $observation->observedAt,
            evidence: array_merge($this->evidence, [
                'reconciliation_source' => $observation->source->value,
                'observation' => $observation->evidence,
            ]),
        );
    }
}
