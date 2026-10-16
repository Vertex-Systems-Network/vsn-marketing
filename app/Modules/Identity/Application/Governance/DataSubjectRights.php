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
use Throwable;

/**
 * Tenant-scoped export, erasure, retention and legal hold. Precedence: an active
 * legal hold (workspace-wide or contact) always wins over erasure and retention.
 * Erasure is all-or-nothing anonymization; append-only legal evidence (consent,
 * suppression, preference, immutable delivery snapshots) is retained and reported,
 * never claimed as erased.
 */
final readonly class DataSubjectRights
{
    private const RETAINED = [
        'consent_records' => 'append-only consent evidence',
        'suppression_records' => 'suppression must persist to prevent re-contact',
        'preference_records' => 'append-only preference evidence',
        'delivery_recipient_snapshots' => 'immutable delivery execution evidence',
    ];

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private AuditRecorder $audit,
        private DataResidencyMap $residency,
    ) {}

    public function placeHold(User $actor, string $workspaceId, ?string $contactId, string $reason): string
    {
        $this->authorize($actor, $workspaceId);
        if ($contactId !== null) {
            $this->contactOrFail($workspaceId, $contactId);
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255) {
            throw new AuthorizationException('A legal hold requires a bounded reason.');
        }
        $id = (string) Str::uuid();
        DB::table('data_legal_holds')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'contact_id' => $contactId, 'reason' => $reason,
            'placed_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($workspaceId, 'privacy.legal_hold.placed', ['hold_id' => $id, 'scope' => $contactId === null ? 'workspace' : 'contact'],
            actorId: (string) $actor->getKey(), subjectType: 'legal_hold', subjectId: $id);

        return $id;
    }

    public function releaseHold(User $actor, string $workspaceId, string $holdId): void
    {
        $this->authorize($actor, $workspaceId);
        $updated = DB::table('data_legal_holds')->where('id', $holdId)->where('workspace_id', $workspaceId)
            ->whereNull('released_at')
            ->update(['released_at' => now(), 'released_by' => $actor->getKey(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new AuthorizationException('Legal hold not found.');
        }
        $this->audit->record($workspaceId, 'privacy.legal_hold.released', ['hold_id' => $holdId],
            actorId: (string) $actor->getKey(), subjectType: 'legal_hold', subjectId: $holdId);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function export(User $actor, string $workspaceId, string $contactId): array
    {
        $this->authorize($actor, $workspaceId);
        $this->residency->assertRoutable($workspaceId, 'files');
        $this->contactOrFail($workspaceId, $contactId);
        $data = [];
        foreach (['contacts' => 'id', 'contact_identities' => 'contact_id', 'contact_list_memberships' => 'contact_id',
            'contact_tag_assignments' => 'contact_id', 'consent_records' => 'contact_id',
            'suppression_records' => 'contact_id', 'preference_records' => 'contact_id',
            'customer_events' => 'contact_id'] as $table => $column) {
            $data[$table] = DB::table($table)->where('workspace_id', $workspaceId)->where($column, $contactId)
                ->get()->map(fn ($row): array => (array) $row)->all();
        }
        $requestId = $this->recordRequest($actor, $workspaceId, $contactId, 'export', 'completed',
            ['tables' => array_map('count', $data)]);
        $this->audit->record($workspaceId, 'privacy.subject.exported', ['request_id' => $requestId, 'counts' => array_map('count', $data)],
            actorId: (string) $actor->getKey(), subjectType: 'contact', subjectId: $contactId);

        return $data;
    }

    /** @return array{status: string, erased: list<string>, retained: array<string, string>} */
    public function erase(User $actor, string $workspaceId, string $contactId): array
    {
        $this->authorize($actor, $workspaceId);
        $this->contactOrFail($workspaceId, $contactId);
        if ($this->held($workspaceId, $contactId)) {
            $result = ['status' => 'blocked_by_legal_hold', 'erased' => [], 'retained' => []];
            $requestId = $this->recordRequest($actor, $workspaceId, $contactId, 'erasure', 'blocked_by_legal_hold', $result);
            $this->audit->record($workspaceId, 'privacy.subject.erasure_blocked', ['request_id' => $requestId],
                actorId: (string) $actor->getKey(), subjectType: 'contact', subjectId: $contactId);

            return $result;
        }

        try {
            $erased = DB::transaction(function () use ($workspaceId, $contactId): array {
                $scoped = fn (string $table) => DB::table($table)->where('workspace_id', $workspaceId)->where('contact_id', $contactId);
                DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)
                    ->update(['first_name' => null, 'last_name' => null, 'display_name' => null, 'updated_at' => now()]);
                foreach ($scoped('contact_identities')->get(['id']) as $identity) {
                    $token = 'erased:'.hash('sha256', (string) $identity->id);
                    DB::table('contact_identities')->where('id', $identity->id)->update([
                        'value' => $token, 'normalized_value' => $token, 'provider_reference' => null, 'updated_at' => now(),
                    ]);
                }
                $scoped('contact_list_memberships')->delete();
                $scoped('contact_tag_assignments')->delete();
                $scoped('customer_events')->update(['subjects' => '[]', 'payload' => '{}', 'source_metadata' => '{}']);

                return ['contacts', 'contact_identities', 'contact_list_memberships', 'contact_tag_assignments', 'customer_events'];
            });
        } catch (Throwable $exception) {
            $result = ['status' => 'failed_rolled_back', 'erased' => [], 'retained' => []];
            $requestId = $this->recordRequest($actor, $workspaceId, $contactId, 'erasure', 'failed_rolled_back', $result);
            $this->audit->record($workspaceId, 'privacy.subject.erasure_failed', ['request_id' => $requestId, 'error' => class_basename($exception)],
                actorId: (string) $actor->getKey(), subjectType: 'contact', subjectId: $contactId);

            return $result;
        }

        $result = ['status' => 'completed', 'erased' => $erased, 'retained' => self::RETAINED];
        $requestId = $this->recordRequest($actor, $workspaceId, $contactId, 'erasure', 'completed', $result);
        $this->audit->record($workspaceId, 'privacy.subject.erased', ['request_id' => $requestId, 'erased' => $erased, 'retained' => array_keys(self::RETAINED)],
            actorId: (string) $actor->getKey(), subjectType: 'contact', subjectId: $contactId);

        return $result;
    }

    /** Deletes expired customer events, skipping anything under legal hold. Returns rows removed. */
    public function applyRetention(User $actor, string $workspaceId): int
    {
        $this->authorize($actor, $workspaceId);
        $days = DB::table('workspace_data_policies')->where('workspace_id', $workspaceId)->value('event_retention_days');
        if (! is_numeric($days) || (int) $days < 1) {
            return 0;
        }
        if ($this->held($workspaceId, null)) {
            $this->audit->record($workspaceId, 'privacy.retention.skipped_legal_hold', [], actorId: (string) $actor->getKey());

            return 0;
        }
        $heldContacts = DB::table('data_legal_holds')->where('workspace_id', $workspaceId)
            ->whereNull('released_at')->whereNotNull('contact_id')->pluck('contact_id')->all();
        $removed = DB::table('customer_events')->where('workspace_id', $workspaceId)
            ->where('occurred_at', '<', now()->subDays((int) $days))
            ->whereNotIn('contact_id', $heldContacts)->delete();
        $this->audit->record($workspaceId, 'privacy.retention.applied', ['days' => (int) $days, 'removed' => $removed, 'held_contacts' => count($heldContacts)],
            actorId: (string) $actor->getKey());

        return $removed;
    }

    private function held(string $workspaceId, ?string $contactId): bool
    {
        return DB::table('data_legal_holds')->where('workspace_id', $workspaceId)->whereNull('released_at')
            ->where(function ($query) use ($contactId): void {
                $query->whereNull('contact_id');
                if ($contactId !== null) {
                    $query->orWhere('contact_id', $contactId);
                }
            })->exists();
    }

    private function authorize(User $actor, string $workspaceId): void
    {
        $organization = DB::table('workspaces')->where('id', $workspaceId)->value('organization_id');
        if (! is_string($organization) || ! $this->authorizer->allows($actor,
            new TenantContext($organization, $workspaceId, null, (string) $actor->getKey()), PermissionCatalog::PRIVACY_MANAGE)) {
            throw new AuthorizationException('Privacy management denied.');
        }
    }

    private function contactOrFail(string $workspaceId, string $contactId): void
    {
        if (! DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)->exists()) {
            throw new AuthorizationException('Contact not found in workspace.');
        }
    }

    private function recordRequest(User $actor, string $workspaceId, string $contactId, string $type, string $status, array $result): string
    {
        $id = (string) Str::uuid();
        DB::table('data_subject_requests')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'contact_id' => $contactId, 'type' => $type,
            'status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'requested_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
