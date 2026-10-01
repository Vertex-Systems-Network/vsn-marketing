<?php

namespace App\Modules\AI\Domain;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;

final class AiContextSourcePolicy
{
    /** @return array{permission: string, classification: string}|null */
    public function contract(mixed $kind): ?array
    {
        return match ($kind) {
            'approved_fact' => ['permission' => PermissionCatalog::CONTACT_READ, 'classification' => 'approved_non_personal'],
            'brand_guideline' => ['permission' => PermissionCatalog::TEMPLATE_CREATE, 'classification' => 'public'],
            'run_note' => ['permission' => PermissionCatalog::JOURNEY_READ, 'classification' => 'approved_non_personal'],
            default => null,
        };
    }
}
