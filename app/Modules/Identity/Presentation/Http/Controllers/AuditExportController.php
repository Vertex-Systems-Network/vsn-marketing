<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Governance\AuditEventExportPage;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AuditExportController
{
    public function __construct(
        private AuditEventExportPage $export,
        private TenantContextStore $tenant,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'before' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }
        $workspaceId = $this->tenant->require()->workspaceId;
        $page = $this->export->read($workspaceId, $input['before'] ?? null, (int) ($input['limit'] ?? 50));
        $this->audit->record($workspaceId, 'identity.audit.metadata_exported', [
            'count' => count($page['items']),
        ], actorId: (string) $actor->getKey());

        return response()->json($page, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
