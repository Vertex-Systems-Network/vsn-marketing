<?php

namespace App\Modules\Providers\Application\Messaging;

use App\Modules\Providers\Domain\Messaging\Contracts\MessagingAdapter;
use App\Modules\Providers\Domain\Messaging\Contracts\MessagingOperationRepository;
use App\Modules\Providers\Domain\Messaging\MessagingIntent;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReserveOfflineMessagingIntent
{
    public function __construct(private MessagingOperationRepository $operations) {}

    /** @param list<string> $grantedScopes */
    public function reserve(MessagingAdapter $adapter, MessagingIntent $intent, array $grantedScopes, DateTimeImmutable $at, string $payloadFingerprint): MessagingOperation
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $payloadFingerprint)) {
            throw new InvalidArgumentException('A SHA256 payload fingerprint is required.');
        }
        $decision = $adapter->prepare($intent, $grantedScopes, $at);
        if (! $decision->offlinePreparationAllowed || $decision->liveDeliveryAllowed) {
            throw new InvalidArgumentException('Only an eligible offline messaging intent may be reserved.');
        }
        // Persist a scope-bound digest, never recipient identity, content or credentials.
        $fingerprint = hash('sha256', json_encode([
            $intent->workspaceId, $intent->brandId, $intent->accountWorkspaceId,
            $intent->providerKey, $intent->channel->value, $intent->recipientIdentityType,
            $intent->recipientReference, $payloadFingerprint,
        ], JSON_THROW_ON_ERROR));

        return $this->operations->reserve($intent->workspaceId, $intent->channel->value,
            $intent->providerKey, $intent->idempotencyKey, $fingerprint);
    }
}
