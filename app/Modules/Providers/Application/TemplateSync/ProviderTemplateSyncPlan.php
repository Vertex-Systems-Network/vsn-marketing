<?php

namespace App\Modules\Providers\Application\TemplateSync;

use App\Modules\Providers\Domain\Templates\ProviderTemplateDrift;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncAction;
use InvalidArgumentException;

final readonly class ProviderTemplateSyncPlan
{
    public function __construct(
        public string $workspaceId,
        public string $providerId,
        public string $canonicalTemplateId,
        public string $canonicalTemplateVersionId,
        public ?string $providerTemplateReference,
        public string $desiredDerivativeIdentity,
        public string $requestIdentity,
        public string $idempotencyKey,
        public ProviderTemplateDrift $drift,
        public ProviderTemplateSyncAction $action,
        public string $reason,
        public ?string $capabilitySourceVersion,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'providerId' => $this->providerId,
            'canonicalTemplateId' => $this->canonicalTemplateId,
            'canonicalTemplateVersionId' => $this->canonicalTemplateVersionId,
            'idempotencyKey' => $this->idempotencyKey,
            'reason' => $this->reason,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Provider template sync plan {$field} must not be empty.");
            }
        }

        foreach ([
            'desired derivative identity' => $this->desiredDerivativeIdentity,
            'request identity' => $this->requestIdentity,
        ] as $label => $value) {
            if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
                throw new InvalidArgumentException("Provider template sync {$label} must be a SHA-256 hex digest.");
            }
        }
    }

    public function canSynchronize(): bool
    {
        return $this->action === ProviderTemplateSyncAction::Synchronize;
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'provider_id' => $this->providerId,
            'canonical_template_id' => $this->canonicalTemplateId,
            'canonical_template_version_id' => $this->canonicalTemplateVersionId,
            'provider_template_reference' => $this->providerTemplateReference,
            'desired_derivative_identity' => $this->desiredDerivativeIdentity,
            'request_identity' => $this->requestIdentity,
            'drift' => $this->drift->value,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'capability_source_version' => $this->capabilitySourceVersion,
            'authoritative_source' => false,
            'network_fetch' => false,
            'live_publish' => false,
        ];
    }
}
