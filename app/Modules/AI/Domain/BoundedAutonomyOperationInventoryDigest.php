<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/**
 * Deterministic integrity checksum for canonical expected-operation evidence.
 * This is not an external provider signature.
 */
final class BoundedAutonomyOperationInventoryDigest
{
    private const array FIELDS = [
        'tenant', 'run_id', 'snapshot_sha256', 'observed_at_unix',
        'expires_at_unix', 'complete', 'operations', 'operation_set_sha256',
    ];

    public static function sha256(array $inventory): string
    {
        $canonical = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $inventory)) {
                throw new InvalidArgumentException('Incomplete expected operation inventory manifest.');
            }
            $canonical[$field] = $inventory[$field];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
