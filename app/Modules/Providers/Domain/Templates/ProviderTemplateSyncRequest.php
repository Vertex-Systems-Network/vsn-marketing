<?php

namespace App\Modules\Providers\Domain\Templates;

use InvalidArgumentException;

final readonly class ProviderTemplateSyncRequest
{
    /**
     * @param  list<string>  $componentVersionIds
     * @param  list<string>  $brandVersionIds
     * @param  list<string>  $mediaKinds
     */
    public function __construct(
        public string $workspaceId,
        public string $providerId,
        public string $canonicalTemplateId,
        public string $canonicalTemplateVersionId,
        public string $canonicalSourceIdentity,
        public array $componentVersionIds,
        public array $brandVersionIds,
        public string $templateKind,
        public array $mediaKinds,
        public string $idempotencyKey,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'providerId' => $this->providerId,
            'canonicalTemplateId' => $this->canonicalTemplateId,
            'canonicalTemplateVersionId' => $this->canonicalTemplateVersionId,
            'templateKind' => $this->templateKind,
            'idempotencyKey' => $this->idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Provider template sync request {$field} must not be empty.");
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $this->canonicalSourceIdentity) !== 1) {
            throw new InvalidArgumentException('Provider template canonical source identity must be a SHA-256 hex digest.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/i', $this->templateKind) !== 1) {
            throw new InvalidArgumentException('Provider template kind must be a bounded stable identifier.');
        }

        if (mb_strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Provider template sync idempotency key must not exceed 191 characters.');
        }

        self::assertStableReferences($this->componentVersionIds, 'component version');
        self::assertStableReferences($this->brandVersionIds, 'brand version');
        self::assertStableReferences($this->mediaKinds, 'media kind');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $components = $this->componentVersionIds;
        $brands = $this->brandVersionIds;
        $mediaKinds = $this->mediaKinds;
        sort($components, SORT_STRING);
        sort($brands, SORT_STRING);
        sort($mediaKinds, SORT_STRING);

        return [
            'workspace_id' => $this->workspaceId,
            'provider_id' => $this->providerId,
            'canonical_template_id' => $this->canonicalTemplateId,
            'canonical_template_version_id' => $this->canonicalTemplateVersionId,
            'canonical_source_identity' => strtolower($this->canonicalSourceIdentity),
            'component_version_ids' => $components,
            'brand_version_ids' => $brands,
            'template_kind' => $this->templateKind,
            'media_kinds' => $mediaKinds,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    /** @return array<string, mixed> */
    public function derivativeInput(): array
    {
        $payload = $this->toArray();
        unset($payload['idempotency_key']);

        return $payload;
    }

    /** @param list<string> $values */
    private static function assertStableReferences(array $values, string $label): void
    {
        $seen = [];

        foreach ($values as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Provider template {$label} reference must not be empty.");
            }

            if (isset($seen[$value])) {
                throw new InvalidArgumentException("Duplicate provider template {$label} reference.");
            }

            $seen[$value] = true;
        }
    }
}
