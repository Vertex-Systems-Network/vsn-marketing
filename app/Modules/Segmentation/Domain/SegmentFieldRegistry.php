<?php

namespace App\Modules\Segmentation\Domain;

final class SegmentFieldRegistry
{
    /** @var array<string, array{table: string, column: string, type: string}> */
    private const FIELDS = [
        'contact.created_at' => ['table' => 'contacts', 'column' => 'created_at', 'type' => 'timestamp'],
        'company.name' => ['table' => 'companies', 'column' => 'name', 'type' => 'text'],
        'company.domain' => ['table' => 'companies', 'column' => 'domain', 'type' => 'text'],
    ];

    /** @return array{table: string, column: string, type: string}|null */
    public function get(string $id): ?array
    {
        return self::FIELDS[$id] ?? null;
    }

    /** @return array<string, array{table: string, column: string, type: string}> */
    public function all(): array
    {
        return self::FIELDS;
    }
}
