<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

interface QuotaSignalInterface
{
    /**
     * Ingest provider quota/rate signal metadata and return a normalized structure for core usage.
     * Examples include remaining, reset_at, window_seconds, scope, unit.
     *
     * @param array $signal
     * @return array{
     *   remaining: ?int,
     *   reset_at: ?int,
     *   window_seconds: ?int,
     *   scope: ?string,
     *   unit: ?string,
     *   raw: array
     * }
     */
    public function ingest(array $signal): array;
}
