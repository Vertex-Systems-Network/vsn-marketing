<?php

namespace App\Modules\Providers\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use App\Modules\Providers\Domain\Provider;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RegisterProvider
{
    public const AUDIT_ACTION = 'provider.registered';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private Clock $clock,
        private ProviderRepository $providers,
        private ProviderTransaction $transaction,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $key,
        string $displayName,
        string $sourceUrl,
        ?string $category = null,
        ?string $sourceVersion = null,
        ?DateTimeImmutable $freshUntil = null,
        array $metadata = [],
    ): Provider {
        $key = strtolower(trim($key));
        $displayName = trim($displayName);
        $category = $this->nullable($category);
        $this->assertToken($key, 'Provider key');
        $this->assertNonEmpty($displayName, 'Provider display name');
        $this->assertSourceUrl($sourceUrl);
        $observedAt = $this->clock->now();

        return $this->transaction->run(function () use ($context, $key, $displayName, $category, $sourceUrl, $sourceVersion, $observedAt, $freshUntil, $metadata): Provider {
            $provider = new Provider(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                key: $key,
                displayName: $displayName,
                category: $category,
                metadata: $metadata,
                sourceUrl: $sourceUrl,
                sourceVersion: $this->nullable($sourceVersion),
                observedAt: $observedAt,
                freshUntil: $freshUntil,
            );
            $this->providers->saveProvider($provider);
            $this->audit->record(
                workspaceId: $context->workspaceId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'provider',
                subjectId: $provider->id,
                evidence: ['provider_key' => $provider->key, 'source_url' => $provider->sourceUrl],
            );

            return $provider;
        });
    }

    private function assertToken(string $value, string $label): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/', $value)) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertNonEmpty(string $value, string $label): void
    {
        if ($value === '') {
            throw new InvalidArgumentException($label.' cannot be empty.');
        }
    }

    private function assertSourceUrl(string $sourceUrl): void
    {
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Provider provenance source URL is invalid.');
        }
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
