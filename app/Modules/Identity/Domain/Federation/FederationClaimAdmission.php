<?php

namespace App\Modules\Identity\Domain\Federation;

/**
 * Post-verification policy only. The calling adapter must verify signature,
 * algorithm and nonce replay with a trusted IdP key before invoking this gate.
 */
final class FederationClaimAdmission
{
    public function allows(
        bool $signatureVerified,
        string $configuredWorkspaceId,
        string $requestWorkspaceId,
        string $configuredIssuer,
        string $assertionIssuer,
        string $configuredAudience,
        string $assertionAudience,
        string $subject,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): bool {
        if (! $signatureVerified || $configuredWorkspaceId === '' || $requestWorkspaceId === '' || ! hash_equals($configuredWorkspaceId, $requestWorkspaceId)) {
            return false;
        }

        if ($configuredIssuer === '' || $assertionIssuer === '' || ! hash_equals($configuredIssuer, $assertionIssuer)) {
            return false;
        }

        if ($configuredAudience === '' || $assertionAudience === '' || ! hash_equals($configuredAudience, $assertionAudience) || $subject === '') {
            return false;
        }

        // Reject future-issued, expired and excessively long-lived assertions.
        return $issuedAt > 0 && $issuedAt <= $now && $expiresAt > $now && $expiresAt - $issuedAt <= 3600;
    }
}
