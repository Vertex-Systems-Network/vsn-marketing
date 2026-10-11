<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/**
 * Deterministic integrity checksum of one aggregate provider outcome.
 *
 * This checksum does not authenticate the provider or authorize effects.
 */
final class BoundedAutonomyRollbackEvidenceDigest
{
    private const array FIELDS = [
        'tenant', 'run_id', 'snapshot_sha256', 'observed_at_unix',
        'attempted_at_unix', 'callback_count', 'outcome',
        'outcome_verified_independently', 'late_callback',
        'duplicate_callback', 'external_cost_minor',
    ];

    public static function sha256(array $record): string
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $record)) {
                throw new InvalidArgumentException('Incomplete aggregate rollback evidence checksum.');
            }
            $payload[$field] = $record[$field];
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
