<?php

namespace App\Modules\Segmentation\Domain;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;

final class SegmentFieldRegistry
{
    /** @var array<string, array{table: string, column: string, type: string, required_permission: string, targetable: bool, previewable: bool}> */
    private const FIELDS = [
        'contact.created_at' => ['table' => 'contacts', 'column' => 'created_at', 'type' => 'timestamp', 'required_permission' => PermissionCatalog::CONTACT_READ, 'targetable' => true, 'previewable' => false],
        'company.name' => ['table' => 'companies', 'column' => 'name', 'type' => 'text', 'required_permission' => PermissionCatalog::CONTACT_READ, 'targetable' => true, 'previewable' => false],
        'company.domain' => ['table' => 'companies', 'column' => 'domain', 'type' => 'text', 'required_permission' => PermissionCatalog::CONTACT_READ, 'targetable' => true, 'previewable' => false],
    ];

    /** @return array{table: string, column: string, type: string, required_permission: string, targetable: bool, previewable: bool}|null */
    public function get(string $id): ?array
    {
        return self::FIELDS[$id] ?? null;
    }

    /** @param list<string> $permissions @return array<string, array{table: string, column: string, type: string, required_permission: string, targetable: bool, previewable: bool}> */
    public function availableTo(array $permissions): array
    {
        $granted = array_fill_keys($permissions, true);

        return array_filter(self::FIELDS, static fn (array $field): bool => $field['targetable'] && isset($granted[$field['required_permission']]));
    }

    /** @return array<string, array{table: string, column: string, type: string, required_permission: string, targetable: bool, previewable: bool}> */
    public function all(): array
    {
        return self::FIELDS;
    }
}
