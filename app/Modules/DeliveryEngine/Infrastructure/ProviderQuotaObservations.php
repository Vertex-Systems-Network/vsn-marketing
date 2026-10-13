<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use DateTimeImmutable;
use stdClass;

/**
 * Provider quota rows are append-only observations. Only the newest observation of
 * each quota dimension is current evidence; older rows of the same dimension are
 * superseded history and must never block admission or failover.
 */
final class ProviderQuotaObservations
{
    private const array DIMENSION = [
        'scope_type',
        'scope_reference',
        'unit',
        'window_type',
        'window_seconds',
        'region',
        'principal_type',
        'principal_reference',
    ];

    /**
     * @param  iterable<mixed>  $rows
     * @return list<stdClass>
     */
    public static function latest(iterable $rows): array
    {
        $latest = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $dimension = json_encode(array_map(
                static fn (string $column): ?string => $row->{$column} === null ? null : (string) $row->{$column},
                self::DIMENSION,
            ), JSON_THROW_ON_ERROR);

            if (! isset($latest[$dimension]) || self::newer($row, $latest[$dimension])) {
                $latest[$dimension] = $row;
            }
        }

        $current = array_values($latest);
        usort($current, static fn (stdClass $left, stdClass $right): int => strcmp((string) $left->id, (string) $right->id));

        return $current;
    }

    private static function newer(stdClass $candidate, stdClass $current): bool
    {
        $order = new DateTimeImmutable((string) $candidate->observed_at) <=> new DateTimeImmutable((string) $current->observed_at);
        if ($order === 0) {
            $order = new DateTimeImmutable((string) $candidate->created_at) <=> new DateTimeImmutable((string) $current->created_at);
        }

        return $order === 0 ? strcmp((string) $candidate->id, (string) $current->id) > 0 : $order > 0;
    }
}
