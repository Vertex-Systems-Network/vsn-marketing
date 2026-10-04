<?php
namespace App\Modules\Providers\Domain\Messaging;
use DateTimeImmutable; use InvalidArgumentException;
final readonly class MessagingOperation {
 /** @param array<string,mixed> $evidence */
 public function __construct(public string $id,public string $workspaceId,public string $channel,public string $providerKey,public string $idempotencyKey,public string $requestFingerprint,public MessagingOperationState $state,public ?string $providerOperationId,public bool $ambiguousOutcome,public array $evidence,public DateTimeImmutable $createdAt,public DateTimeImmutable $updatedAt) {
  foreach([$id,$workspaceId,$channel,$providerKey,$idempotencyKey,$requestFingerprint] as $value) if(trim($value)==='') throw new InvalidArgumentException('Messaging operation identity fields must be non-blank.');
 }
 public function reconcile(MessagingProviderOutcome $outcome): self {
  if($this->providerOperationId!==null && $this->providerOperationId!==$outcome->providerOperationId) throw new InvalidArgumentException('Provider operation ID does not match the reserved messaging operation.');
  if($this->state->isTerminal()) { if($this->state===$outcome->state && $this->providerOperationId===$outcome->providerOperationId) return $this; throw new InvalidArgumentException('A terminal messaging operation cannot transition to another outcome.'); }
  $next=$outcome->ambiguous?MessagingOperationState::Ambiguous:$outcome->state;
  return new self($this->id,$this->workspaceId,$this->channel,$this->providerKey,$this->idempotencyKey,$this->requestFingerprint,$next,$this->providerOperationId??$outcome->providerOperationId,$outcome->ambiguous||$this->ambiguousOutcome,array_merge($this->evidence,['last_reconciliation_source'=>$outcome->source->value,'last_observed_at'=>$outcome->observedAt->format(DateTimeImmutable::ATOM),'provider_evidence'=>$outcome->evidence]),$this->createdAt,$outcome->observedAt);
 }
}