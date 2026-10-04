<?php
namespace App\Modules\Providers\Domain\Messaging\Contracts;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingProviderOutcome;
interface MessagingOperationRepository {
 public function reserve(string $workspaceId,string $channel,string $providerKey,string $idempotencyKey,string $requestFingerprint): MessagingOperation;
 public function reconcile(MessagingOperation $operation,MessagingProviderOutcome $outcome): MessagingOperation;
}