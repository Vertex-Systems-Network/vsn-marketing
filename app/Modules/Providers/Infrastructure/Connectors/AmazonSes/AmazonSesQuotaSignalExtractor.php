<?php

namespace App\Modules\Providers\Infrastructure\Connectors\AmazonSes;

use App\Modules\Providers\Domain\Connectors\Contracts\QuotaSignalExtractor;
use App\Modules\Providers\Domain\Connectors\ProviderQuotaSignal;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;

final class AmazonSesQuotaSignalExtractor implements QuotaSignalExtractor
{
    public function extract(ProviderResponseEvidence $response, string $operation): array
    {
        $quota = $response->metadata['send_quota'] ?? null;
        if (! is_array($quota)) {
            return [];
        }

        $region = $this->stringOrNull($response->metadata['region'] ?? null);
        $tier = $this->stringOrNull($response->metadata['access_tier'] ?? null);
        $sourceEvidence = [
            'provider_request_id' => $response->providerRequestId,
            'observed_at' => $response->metadata['observed_at'] ?? null,
            'http_status' => $response->httpStatus,
        ];
        $signals = [];

        $max24 = $this->numericString($quota['max_24_hour_send'] ?? null);
        $sent24 = $this->numericString($quota['sent_last_24_hours'] ?? null);
        if ($max24 !== null) {
            $remaining = null;
            if ($sent24 !== null) {
                $remaining = $this->numericString(max(0.0, (float) $max24 - (float) $sent24));
            }

            $signals[] = new ProviderQuotaSignal(
                operation: $operation,
                scopeType: 'account',
                unit: 'recipient',
                windowType: 'rolling-24h',
                sourceKey: 'GetAccount.SendQuota.Max24HourSend',
                windowSeconds: 86400,
                region: $region,
                accountTier: $tier,
                limitValue: $max24,
                remainingValue: $remaining,
                evidence: $sourceEvidence,
            );
        }

        $maxRate = $this->numericString($quota['max_send_rate'] ?? null);
        if ($maxRate !== null) {
            $signals[] = new ProviderQuotaSignal(
                operation: $operation,
                scopeType: 'account',
                unit: 'recipient',
                windowType: 'second',
                sourceKey: 'GetAccount.SendQuota.MaxSendRate',
                windowSeconds: 1,
                region: $region,
                accountTier: $tier,
                limitValue: $maxRate,
                evidence: $sourceEvidence,
            );
        }

        return $signals;
    }

    private function stringOrNull(mixed $value): ?string
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
}
