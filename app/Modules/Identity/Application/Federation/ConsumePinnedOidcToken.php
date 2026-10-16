<?php

namespace App\Modules\Identity\Application\Federation;

/** No public route or session creation; the caller supplies server-owned configuration. */
final readonly class ConsumePinnedOidcToken
{
    public function __construct(private PinnedOidcTokenVerifier $verifier, private ConsumeVerifiedAssertion $consumer) {}

    public function execute(string $token, string $workspaceId, string $configuredWorkspaceId, string $issuer, string $clientId, string $expectedNonce, string $publicKeyPem, int $now, ?string $expectedKeyId = null): string
    {
        $claims = $this->verifier->verify($token, $issuer, $clientId, $expectedNonce, $publicKeyPem, $now, $expectedKeyId);

        return $this->consumer->execute(true, $workspaceId, $configuredWorkspaceId, $issuer,
            $claims['issuer'], $clientId, $claims['audience'], $claims['subject'], $claims['replayId'],
            $claims['issuedAt'], $claims['expiresAt'], $now);
    }
}
