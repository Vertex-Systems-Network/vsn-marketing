<?php

namespace App\Modules\Templates\Domain\Governance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReusableGovernanceEvent
{
    /**
     * @param  array<string, mixed>  $auditProvenance
     */
    public function __construct(
        public int $sequence,
        public ?ReusableApprovalStatus $fromStatus,
        public ReusableApprovalStatus $toStatus,
        public string $actorId,
        public array $auditProvenance,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($this->sequence < 1) {
            throw new InvalidArgumentException('Reusable governance event sequence must be positive.');
        }

        if (trim($this->actorId) === '') {
            throw new InvalidArgumentException('Reusable governance event actor id must not be empty.');
        }

        self::assertPublicAuditValue($this->auditProvenance, 'auditProvenance');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'from_status' => $this->fromStatus?->value,
            'to_status' => $this->toStatus->value,
            'actor_id' => $this->actorId,
            'audit_provenance' => $this->auditProvenance,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    private static function assertPublicAuditValue(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException("Reusable governance audit number must be finite: {$path}");
            }

            return;
        }

        if (is_string($value)) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("Reusable governance audit value contains forbidden control characters: {$path}");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $segment = (string) $key;

                if (
                    is_string($key)
                    && preg_match(
                        '/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key|provider[_-]?payload|upload[_-]?id|campaign[_-]?id|schedule|publishing?/i',
                        $key,
                    ) === 1
                ) {
                    throw new InvalidArgumentException("Sensitive or PHASE-07 governance audit key is forbidden: {$path}.{$segment}");
                }

                self::assertPublicAuditValue($nested, $path.'.'.$segment);
            }

            return;
        }

        throw new InvalidArgumentException("Reusable governance audit provenance must be JSON-compatible: {$path}");
    }
}
