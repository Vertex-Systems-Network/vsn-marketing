<?php

namespace App\Modules\Providers\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\AuthFamily;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use App\Modules\Providers\Domain\ProviderConnection;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Domain\SecretReference;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CreateProviderConnection
{
    public const AUDIT_ACTION = 'provider.connection.created';

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
        string $name,
        AuthFamily $authFamily,
        string $secretReference,
        string $sourceUrl,
        ProviderReadinessStatus $readiness = ProviderReadinessStatus::Unknown,
        array $requestedScopes = [],
        array $grantedScopes = [],
        array $roles = [],
        ?string $accessTier = null,
        ?string $region = null,
        ?string $principalType = null,
        ?string $principalReference = null,
        ?string $providerReviewStatus = null,
        ?DateTimeImmutable $tokenExpiresAt = null,
        bool $refreshSupported = false,
        ?DateTimeImmutable $lastRotatedAt = null,
        ?string $sourceVersion = null,
        ?DateTimeImmutable $freshUntil = null,
        array $metadata = [],
    ): ProviderConnection {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Provider connection name cannot be empty.');
        }
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Provider connection provenance source URL is invalid.');
        }

        $connection = new ProviderConnection(
            id: $this->identifiers->next(),
            workspaceId: $context->workspaceId,
            providerId: $providerId,
            name: $name,
            readiness: $readiness,
            authFamily: $authFamily,
            secretReference: new SecretReference($secretReference),
            requestedScopes: $this->tokens($requestedScopes),
            grantedScopes: $this->tokens($grantedScopes),
            roles: $this->tokens($roles),
            accessTier: $this->nullable($accessTier),
            region: $this->nullable($region),
            principalType: $this->nullable($principalType),
            principalReference: $this->nullable($principalReference),
            providerReviewStatus: $this->nullable($providerReviewStatus),
            tokenExpiresAt: $tokenExpiresAt,
            refreshSupported: $refreshSupported,
            lastRotatedAt: $lastRotatedAt,
            metadata: $metadata,
            sourceUrl: $sourceUrl,
            sourceVersion: $this->nullable($sourceVersion),
            observedAt: $this->clock->now(),
            freshUntil: $freshUntil,
        );

        return $this->transaction->run(function () use ($context, $connection): ProviderConnection {
            $this->providers->saveConnection($connection);
            $this->audit->record(
                workspaceId: $context->workspaceId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'provider_connection',
                subjectId: $connection->id,
                evidence: [
                    'provider_id' => $connection->providerId,
                    'readiness' => $connection->readiness->value,
                    'auth_family' => $connection->authFamily->value,
                    'access_tier' => $connection->accessTier,
                    'region' => $connection->region,
                ],
            );

            return $connection;
        });
    }

    private function tokens(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Provider scope and role values must be strings.');
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
