<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/**
 * Canonical checksum of immutable per-arm assignment evidence and its plan.
 * An integrity commitment is NOT a provider signature or action authority.
 */
final class BoundedAutonomyArmEvidenceDigest
{
    public static function sha256(string $planSha256, array $assignedByArm): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $planSha256) !== 1
            || $assignedByArm === [] || count($assignedByArm) > 16) {
            throw new InvalidArgumentException('Untrusted frozen arm commitment scope.');
        }

        foreach ($assignedByArm as $variant => $count) {
            if (! is_string($variant) || $variant === ''
                || ! is_int($count) || $count < 0 || $count > 1000000) {
                throw new InvalidArgumentException('Untrusted frozen arm count.');
            }
        }
        ksort($assignedByArm, SORT_STRING);

        return hash('sha256', json_encode([
            'plan_sha256' => $planSha256,
            'assigned_by_arm' => $assignedByArm,
        ], JSON_THROW_ON_ERROR));
    }
}
