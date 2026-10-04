<?php
namespace App\Modules\Providers\Domain\Messaging;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use DateTimeImmutable; use InvalidArgumentException;
final readonly class MessagingProviderOutcome {
 /** @param array<string,mixed> $evidence */
 public function __construct(public string $providerOperationId, public MessagingOperationState $state, public ReconciliationSource $source, public DateTimeImmutable $observedAt, public array $evidence=[], public bool $ambiguous=false) {
  if (trim($providerOperationId)==='') throw new InvalidArgumentException('Provider operation ID must be non-blank.');
  if ($state===MessagingOperationState::Reserved) throw new InvalidArgumentException('Provider outcome cannot be reserved.');
 }
}