<?php

namespace App\Modules\DeliveryEngine\Domain\Eligibility;

use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EligibilityContext
{
    public function __construct(
        public MessageIntentType $messagePurpose,
        public ?string $jurisdiction,
        public ?string $subscriberType,
        public ?string $solicitationBasis,
        public ?string $relationshipBasis,
        public PolicyBasisType $policyBasis,
        public bool $policyBasisEvidencePresent,
        public EligibilityOutcome $jurisdictionPolicyOutcome,
        public ?string $policyVersion,
        public ?DateTimeImmutable $policyEffectiveAt,
        public ?string $providerKey,
        public bool $providerContextKnown,
        public bool $suppressionApplies,
        public bool $objectionApplies,
    ) {
        foreach ([
            'jurisdiction' => $jurisdiction,
            'subscriberType' => $subscriberType,
            'solicitationBasis' => $solicitationBasis,
            'relationshipBasis' => $relationshipBasis,
            'policyVersion' => $policyVersion,
            'providerKey' => $providerKey,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException($field.' must be null or non-blank.');
            }
        }

        if ($policyVersion !== null && mb_strlen($policyVersion) > 120) {
            throw new InvalidArgumentException('policyVersion exceeds storage limit.');
        }

        if ($providerKey !== null && mb_strlen($providerKey) > 120) {
            throw new InvalidArgumentException('providerKey exceeds storage limit.');
        }

        if (($policyVersion === null) !== ($policyEffectiveAt === null)) {
            throw new InvalidArgumentException('Policy version and effective time must be supplied together.');
        }
    }

    public function hasMinimumPolicyContext(): bool
    {
        return $this->jurisdiction !== null
            && $this->subscriberType !== null
            && $this->solicitationBasis !== null
            && $this->relationshipBasis !== null
            && $this->policyVersion !== null
            && $this->policyEffectiveAt !== null
            && $this->providerKey !== null
            && $this->providerContextKnown;
    }
}
