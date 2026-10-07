<?php

namespace App\Modules\Providers\Domain\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class EngagementFact
{
    public function __construct(
        public string $tenantId,
        public string $providerKey,
        public string $metric,
        public int $value,
        public string $sourceLineage,
        public string $observedAt,
        public bool $isTotalKnown = false,
        public ?string $receivedAt = null,
        public string $unit = 'count',
        public int $definitionVersion = 1,
        public ?string $limitation = null,
        public ?string $brandId = null,
    ) {
        foreach ([$this->tenantId, $this->providerKey, $this->metric, $this->sourceLineage, $this->observedAt] as $field) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('Analytics facts require tenant, source, metric and lineage.');
            }
        }
        if (! preg_match('/^[a-zA-Z0-9._:-]{1,64}$/', $this->providerKey)
            || ! preg_match('/^[a-zA-Z0-9._:-]{1,120}$/', $this->metric)) {
            throw new InvalidArgumentException('Provider analytics identifiers must be bounded stable keys.');
        }
        if ($this->value < 0 || $this->value > 1000000000000000) {
            throw new InvalidArgumentException('Analytics fact values must be bounded non-negative integers.');
        }
        if ($this->unit !== 'count' || $this->definitionVersion !== 1) {
            throw new InvalidArgumentException('Only provider count metric definition v1 is supported.');
        }
        if (strlen($this->sourceLineage) > 512 || ($this->limitation !== null && strlen($this->limitation) > 500)) {
            throw new InvalidArgumentException('Provider analytics lineage or limitation is too large.');
        }
        try {
            $observed = new DateTimeImmutable($this->observedAt);
            $received = new DateTimeImmutable($this->receivedAtValue());
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Provider analytics timestamps must be valid.', 0, $exception);
        }
        if ($observed->getOffset() !== 0 || $received->getOffset() !== 0 || $observed > $received) {
            throw new InvalidArgumentException('Provider analytics requires ordered UTC observed and received timestamps.');
        }
    }

    public function receivedAtValue(): string
    {
        return $this->receivedAt ?? $this->observedAt;
    }

    public function definition(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'provider_metric' => $this->metric,
            'unit' => $this->unit,
            'version' => $this->definitionVersion,
            'semantics' => 'provider_reported_aggregate',
            'cross_provider_equivalent' => false,
            'limitation' => $this->limitation ?? 'Provider-defined aggregate; do not compare or sum across provider definitions without a separate verified mapping.',
        ];
    }

    public function isUnknownTotal(): bool
    {
        return ! $this->isTotalKnown;
    }
}
