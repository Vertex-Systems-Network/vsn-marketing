<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Governance\AuditEventExportPage;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AuditExportController
{
    public function __construct(private AuditEventExportPage $export, private TenantContextStore $tenant) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'before' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->export->read(
            $this->tenant->require()->workspaceId,
            $input['before'] ?? null,
            (int) ($input['limit'] ?? 50),
        ));
    }
}
