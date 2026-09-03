<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Gmail;

use App\Modules\Providers\Domain\Connectors\Contracts\QuotaSignalExtractor;
use App\Modules\Providers\Domain\Connectors\ProviderQuotaSignal;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;
use DateTimeImmutable;
use Exception;

final class GmailQuotaSignalExtractor implements QuotaSignalExtractor
{
    public function extract(ProviderResponseEvidence $response, string $operation): array
    {
        $rawSignals = $response->metadata['quota_signals'] ?? null;
        if (! is_array($rawSignals)) {
            return [];
        }

        $signals = [];
        foreach ($rawSignals as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $scopeType = $this->string($raw['scope_type'] ?? null);
            $windowType = $this->string($raw['window_type'] ?? null);
            $sourceKey = $this->string($raw['source_key'] ?? null);
            if (! in_array($scopeType, ['project', 'user'], true) || $windowType === null || $sourceKey === null) {
                continue;
            }

            $windowSeconds = $raw['window_seconds'] ?? null;
            if (! is_int($windowSeconds) || $windowSeconds <= 0) {
                $windowSeconds = null;
            }

            $signals[] = new ProviderQuotaSignal(
                operation: $operation,
                scopeType: $scopeType,
                unit: 'quota_unit',
                windowType: $windowType,
                sourceKey: $sourceKey,
                scopeReference: $scopeType === 'project' ? $this->string($raw['scope_reference'] ?? null) : null,
                windowSeconds: $windowSeconds,
                principalType: $scopeType === 'user' ? 'user' : null,
                principalReference: $scopeType === 'user' ? $this->string($raw['scope_reference'] ?? null) : null,
                accountTier: $this->string($raw['project_cohort'] ?? null),
                limitValue: $this->numericString($raw['limit_value'] ?? null),
                remainingValue: $this->numericString($raw['remaining_value'] ?? null),
                resetsAt: $this->date($raw['resets_at'] ?? null),
                evidence: [
                    'provider_request_id' => $response->providerRequestId,
                    'observed_at' => $response->metadata['observed_at'] ?? null,
                    'quota_model_changed_at' => '2026-05-01',
                    'project_cohort' => $raw['project_cohort'] ?? null,
                ],
            );
        }

        return $signals;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function numericString(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number) || $number < 0) {
            return null;
        }

        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
