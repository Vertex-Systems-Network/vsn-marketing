<?php

namespace App\Modules\Providers\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use App\Modules\Providers\Domain\ProviderCapability;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RecordProviderCapability
{
    public const AUDIT_ACTION = 'provider.capability.recorded';

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
        CapabilitySupport $support,
        string $sourceUrl,
        ?string $connectionId = null,
        array $requiredScopes = [],
        array $requiredRoles = [],
        array $constraints = [],
        ?string $sourceVersion = null,
        ?DateTimeImmutable $freshUntil = null,
    ): ProviderCapability {
        $operation = strtolower(trim($operation));
        if (! preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $operation)) {
            throw new InvalidArgumentException('Provider capability operation is invalid.');
        }
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Provider capability provenance source URL is invalid.');
        }

        $capability = new ProviderCapability(
            id: $this->identifiers->next(),
            workspaceId: $context->workspaceId,
            providerId: $providerId,
            connectionId: $connectionId,
            operation: $operation,
            support: $support,
            requiredScopes: $this->tokens($requiredScopes),
            requiredRoles: $this->tokens($requiredRoles),
            constraints: $constraints,
            sourceUrl: $sourceUrl,
            sourceVersion: $this->nullable($sourceVersion),
            observedAt: $this->clock->now(),
            freshUntil: $freshUntil,
        );

        return $this->transaction->run(function () use ($context, $capability): ProviderCapability {
            $this->providers->saveCapability($capability);
            $this->audit->record(
                workspaceId: $context->workspaceId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'provider_capability',
                subjectId: $capability->id,
                evidence: [
                    'provider_id' => $capability->providerId,
                    'connection_id' => $capability->connectionId,
                    'operation' => $capability->operation,
                    'support' => $capability->support->value,
                ],
            );

            return $capability;
        });
    }

    private function tokens(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Provider capability scope and role values must be strings.');
            }
            $value = trim($value);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
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
