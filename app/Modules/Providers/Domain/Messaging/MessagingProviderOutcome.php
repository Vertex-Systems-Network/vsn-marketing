<?php

namespace App\Modules\Providers\Domain\Messaging;

use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MessagingProviderOutcome
{
    /** @param array<string,mixed> $evidence */
    public function __construct(public string $providerOperationId, public MessagingOperationState $state, public ReconciliationSource $source, public DateTimeImmutable $observedAt, public array $evidence = [], public bool $ambiguous = false)
    {
        if (trim($providerOperationId) === '' || strlen($providerOperationId) > 191) {
            throw new InvalidArgumentException('Provider operation ID must be non-blank.');
        }
        foreach ($evidence as $key => $value) {
            if ($key !== 'provider_status' || ! is_string($value) || ! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $value)) {
                throw new InvalidArgumentException('Only normalized provider status is allowed in messaging outcome evidence.');
            }
        }
        if ($state === MessagingOperationState::Reserved) {
            throw new InvalidArgumentException('Provider outcome cannot be reserved.');
        }
    }
}
