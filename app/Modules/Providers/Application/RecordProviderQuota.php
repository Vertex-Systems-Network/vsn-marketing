<?php

namespace App\Modules\Providers\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use App\Modules\Providers\Domain\ProviderQuota;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RecordProviderQuota
{
    public const AUDIT_ACTION = 'provider.quota.recorded';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private Clock $clock,
        private ProviderRepository $providers,
        private ProviderTransaction $transaction,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $providerId,
        string $operation,
        string $scopeType,
        string $unit,
        string $windowType,
        string $sourceUrl,
        ?string $connectionId = null,
        ?string $scopeReference = null,
        ?int $windowSeconds = null,
        ?string $region = null,
        ?string $principalType = null,
        ?string $principalReference = null,
        ?string $accountTier = null,
        ?string $limitValue = null,
        ?string $usedValue = null,
        ?string $remainingValue = null,
        ?DateTimeImmutable $resetsAt = null,
        bool $dynamicallyDiscovered = false,
        ?string $discoveryKey = null,
        array $metadata = [],
        ?string $sourceVersion = null,
        ?DateTimeImmutable $freshUntil = null,
    ): ProviderQuota {
        $operation = $this->token($operation, 'Provider quota operation', 191);
        $scopeType = $this->token($scopeType, 'Provider quota scope', 64);
        $unit = $this->token($unit, 'Provider quota unit', 64);
        $windowType = $this->token($windowType, 'Provider quota window', 64);
        if ($windowSeconds !== null && $windowSeconds <= 0) {
            throw new InvalidArgumentException('Provider quota window seconds must be positive.');
        }
        foreach ([$limitValue, $usedValue, $remainingValue] as $value) {
            if ($value !== null && ! preg_match('/^\d+(?:\.\d{1,6})?$/', $value)) {
                throw new InvalidArgumentException('Provider quota numeric values must be non-negative decimal strings.');
            }
        }
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Provider quota provenance source URL is invalid.');
        }

        $quota = new ProviderQuota(
            id: $this->identifiers->next(),
            workspaceId: $context->workspaceId,
            providerId: $providerId,
            connectionId: $connectionId,
            operation: $operation,
            scopeType: $scopeType,
            scopeReference: $this->nullable($scopeReference),
            unit: $unit,
            windowType: $windowType,
            windowSeconds: $windowSeconds,
            region: $this->nullable($region),
            principalType: $this->nullable($principalType),
            principalReference: $this->nullable($principalReference),
            accountTier: $this->nullable($accountTier),
            limitValue: $limitValue,
            usedValue: $usedValue,
            remainingValue: $remainingValue,
            resetsAt: $resetsAt,
            dynamicallyDiscovered: $dynamicallyDiscovered,
            discoveryKey: $this->nullable($discoveryKey),
            metadata: $metadata,
            sourceUrl: $sourceUrl,
            sourceVersion: $this->nullable($sourceVersion),
            observedAt: $this->clock->now(),
            freshUntil: $freshUntil,
        );

        return $this->transaction->run(function () use ($context, $quota): ProviderQuota {
            $this->providers->saveQuota($quota);
            $this->audit->record(
                workspaceId: $context->workspaceId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'provider_quota',
                subjectId: $quota->id,
                evidence: [
                    'provider_id' => $quota->providerId,
                    'connection_id' => $quota->connectionId,
                    'operation' => $quota->operation,
                    'scope_type' => $quota->scopeType,
                    'unit' => $quota->unit,
                    'window_type' => $quota->windowType,
                    'dynamic' => $quota->dynamicallyDiscovered,
                ],
            );

            return $quota;
        });
    }

    private function token(string $value, string $label, int $max): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $max || ! preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $value)) {
            throw new InvalidArgumentException($label.' is invalid.');
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
