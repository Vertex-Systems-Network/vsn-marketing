<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

use InvalidArgumentException;

final readonly class SenderEligibilityContext
{
    public function __construct(
        public SenderPurpose $purpose,
        public ?string $providerKey,
        public ?string $policyKey,
        public ?string $policyVersion,
        public array $policyContext = [],
    ) {
        if ($this->providerKey !== null && trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Provider key must not be blank when supplied.');
        }

        if (($this->policyKey === null) !== ($this->policyVersion === null)) {
            throw new InvalidArgumentException('Policy key and policy version must be supplied together.');
        }

        if ($this->policyKey !== null && trim($this->policyKey) === '') {
            throw new InvalidArgumentException('Policy key must not be blank when supplied.');
        }

        if ($this->policyVersion !== null && trim($this->policyVersion) === '') {
            throw new InvalidArgumentException('Policy version must not be blank when supplied.');
        }

        SenderRecordGuard::assertNoSecretMaterial($this->policyContext, 'policy_context');
    }

    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'provider_key' => $this->providerKey,
            'policy_key' => $this->policyKey,
            'policy_version' => $this->policyVersion,
            'policy_context' => $this->policyContext,
        ];
    }
}
