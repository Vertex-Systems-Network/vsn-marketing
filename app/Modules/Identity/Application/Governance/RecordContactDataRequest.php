<?php

namespace App\Modules\Identity\Application\Governance;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable manual-review request; no deletion or export runs from this call. */
final readonly class RecordContactDataRequest
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private ContactDataInventory $inventory,
        private AuditRecorder $audit,
    ) {}

    public function record(
        User $operator,
        TenantContext $context,
        string $contactId,
        string $action,
        string $sourceReference,
        bool $subjectIdentityVerified,
    ): string {
        if (! in_array($action, ['export', 'erase', 'retention_expire'], true)
            || $sourceReference === '' || strlen($sourceReference) > 256 || ! $subjectIdentityVerified
            || ! $this->authorizer->allows($operator, $context, $action === 'export' ? PermissionCatalog::CONTACT_READ : PermissionCatalog::CONTACT_WRITE)) {
            throw new AuthorizationException('Contact data request denied.');
        }

        return DB::transaction(function () use ($operator, $context, $contactId, $action, $sourceReference): string {
            $contact = DB::table('contacts')->where('id', $contactId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first(['workspace_id']);
            $held = DB::table('contact_legal_holds')->where('workspace_id', $context->workspaceId)
                ->where('contact_id', $contactId)->whereNull('released_at')->exists();
            if ($contact === null || ($action !== 'export' && $held)) {
                throw new AuthorizationException('Contact data request denied.');
            }

            $fingerprint = hash('sha256', $sourceReference);
            $existing = DB::table('contact_data_requests')->where('workspace_id', $context->workspaceId)
                ->where('action', $action)->where('source_fingerprint', $fingerprint)->first(['id', 'contact_id']);
            if ($existing !== null) {
                if ($existing->contact_id !== $contactId) {
                    throw new AuthorizationException('Conflicting data request replay denied.');
                }

                return (string) $existing->id;
            }

            $id = (string) Str::uuid();
            $snapshot = $this->inventory->snapshot($context->workspaceId, $contactId);
            DB::table('contact_data_requests')->insert([
                'id' => $id, 'workspace_id' => $context->workspaceId, 'contact_id' => $contactId,
                'requested_by' => $operator->getKey(), 'action' => $action,
                'status' => 'pending_manual_review', 'source_fingerprint' => $fingerprint,
                'inventory_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'privacy.contact_request.recorded', [
                'request_id' => $id, 'action' => $action,
            ], actorId: (string) $operator->getKey(), subjectType: 'contact', subjectId: $contactId);

            return $id;
        });
    }
}
