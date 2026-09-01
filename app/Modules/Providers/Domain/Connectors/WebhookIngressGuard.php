<?php

namespace App\Modules\Providers\Domain\Connectors;

use App\Modules\Providers\Domain\Connectors\Contracts\WebhookReplayGuard;
use App\Modules\Providers\Domain\Connectors\Contracts\WebhookVerifier;
use UnexpectedValueException;

final readonly class WebhookIngressGuard
{
    public function __construct(
        private WebhookVerifier $verifier,
        private WebhookReplayGuard $replayGuard,
    ) {}

    public function verifyAndClaim(
        string $workspaceId,
        string $connectorKey,
        WebhookRequest $request,
        bool $verificationRequired = true,
        bool $replayProtectionRequired = true,
    ): WebhookVerificationResult
    {
        $result = $this->verifier->verify($request);

        if (! $result->accepts($verificationRequired)) {
            throw new UnexpectedValueException('Webhook authenticity verification failed closed.');
        }

        if (! $replayProtectionRequired) {
            return $result;
        }

        $key = trim((string) $result->deduplicationKey);
        if ($key === '') {
            throw new UnexpectedValueException('Webhook replay protection requires a deduplication key.');
        }

        if (! $this->replayGuard->claim($workspaceId, $connectorKey, $key, $request->receivedAt)) {
            throw new UnexpectedValueException('Webhook replay or duplicate delivery detected.');
        }

        return $result;
    }
}
