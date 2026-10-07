<?php

namespace App\Modules\Providers\Application\Community;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Community\CommunityAccess;
use App\Modules\Providers\Domain\Community\CommunityInboundVerifier;
use App\Modules\Providers\Domain\Community\CommunityItem;
use App\Modules\Providers\Domain\Community\CommunityModerationState;
use App\Modules\Providers\Domain\Community\CommunityProposalState;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class CommunityInbox
{
    public const MAX_RECENT = 100;

    public function __construct(
        private DatabaseManager $database,
        private CommunityAccess $access,
        private Clock $clock,
        private ?CommunityInboundVerifier $verifier = null,
    ) {}

    public function admit(TenantContext $actor, CommunityItem $item, DateTimeImmutable $receivedAt): string
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_READ);
        if ($item->tenantId !== $actor->workspaceId) {
            throw new AuthorizationException('Community inbound workspace scope denied.');
        }
        if ($receivedAt->getOffset() !== 0 || $receivedAt > $this->clock->now()) {
            throw new InvalidArgumentException('Community inbound receipt time must be current-or-past UTC.');
        }
        if ($this->verifier === null) {
            throw new RuntimeException('Community inbound verifier is not configured.');
        }

        $verified = $this->verifier->verify($actor, $item);
        if (! is_string($verified) || trim($verified) === '' || strlen($verified) > 512) {
            throw new InvalidArgumentException('Community inbound evidence could not be verified.');
        }

        $sourceKey = hash('sha256', json_encode([
            $actor->workspaceId,
            $item->providerKey,
            $item->externalId,
        ], JSON_THROW_ON_ERROR));
        $authorHash = hash('sha256', $item->authorExternalId);
        $provenanceHash = hash('sha256', $item->provenanceUrl);
        $verificationHash = hash('sha256', $verified);
        $fingerprint = hash('sha256', json_encode([
            $sourceKey,
            $item->type->value,
            $authorHash,
            $item->body,
            $provenanceHash,
            $verificationHash,
        ], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use (
            $actor,
            $item,
            $receivedAt,
            $sourceKey,
            $authorHash,
            $provenanceHash,
            $verificationHash,
            $fingerprint,
        ): string {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $existing = $this->database->table('community_items')
                ->where('source_key', $sourceKey)
                ->first();

            if ($existing instanceof stdClass) {
                if ((string) $existing->workspace_id !== $actor->workspaceId
                    || ($actor->brandId !== null && (string) $existing->brand_id !== $actor->brandId)) {
                    throw new AuthorizationException('Community inbound replay scope denied.');
                }

                return hash_equals((string) $existing->fingerprint, $fingerprint) ? 'replayed' : 'conflict';
            }

            $now = $this->clock->now();
            $this->database->table('community_items')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $actor->workspaceId,
                'brand_id' => $actor->brandId,
                'provider_key' => $item->providerKey,
                'source_key' => $sourceKey,
                'item_type' => $item->type->value,
                'author_hash' => $authorHash,
                'body' => $item->body,
                'provenance_hash' => $provenanceHash,
                'verification_hash' => $verificationHash,
                'fingerprint' => $fingerprint,
                'moderation_state' => CommunityModerationState::Open->value,
                'assigned_actor_id' => null,
                'proposal_text' => null,
                'proposal_state' => null,
                'received_at' => $receivedAt,
                'updated_at' => $now,
            ]);

            return 'admitted';
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function recent(TenantContext $actor): array
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_READ);

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'provider_key' => (string) $row->provider_key,
            'type' => (string) $row->item_type,
            'body' => (string) $row->body,
            'moderation_state' => (string) $row->moderation_state,
            'assigned_actor_id' => $row->assigned_actor_id === null ? null : (string) $row->assigned_actor_id,
            'proposal_text' => $row->proposal_text === null ? null : (string) $row->proposal_text,
            'proposal_state' => $row->proposal_state === null ? null : (string) $row->proposal_state,
            'received_at' => (new DateTimeImmutable((string) $row->received_at))->format(DATE_ATOM),
            'source_key' => (string) $row->source_key,
            'author_hash' => (string) $row->author_hash,
            'provenance_hash' => (string) $row->provenance_hash,
        ], $this->scope($actor)->orderByDesc('received_at')->orderBy('id')->limit(self::MAX_RECENT)->get()->all());
    }

    public function assign(TenantContext $actor, string $itemId, string $assigneeActorId): void
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_MODERATE);
        $membershipExists = $this->database->table('workspace_memberships')
            ->where('workspace_id', $actor->workspaceId)
            ->where('user_id', $assigneeActorId)
            ->exists();
        if (! $membershipExists) {
            throw new AuthorizationException('Community assignment requires a current workspace member.');
        }

        $this->mutate($actor, $itemId, ['assigned_actor_id' => $assigneeActorId]);
    }

    public function moderate(TenantContext $actor, string $itemId, CommunityModerationState $state): void
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_MODERATE);
        $this->mutate($actor, $itemId, ['moderation_state' => $state->value]);
    }

    public function propose(TenantContext $actor, string $itemId, string $text): void
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_READ);
        $this->permit($actor, PermissionCatalog::AI_EXECUTE);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) {
            throw new InvalidArgumentException('Community response proposal must contain 1-4000 characters.');
        }

        $this->mutate($actor, $itemId, [
            'proposal_text' => $text,
            'proposal_state' => CommunityProposalState::Proposed->value,
        ]);
    }

    public function approveProposal(TenantContext $actor, string $itemId): void
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_MODERATE);
        $this->permit($actor, PermissionCatalog::AI_APPROVE);

        $query = $this->scope($actor)->where('id', $itemId)
            ->where('proposal_state', CommunityProposalState::Proposed->value);
        if ($query->update([
            'proposal_state' => CommunityProposalState::Approved->value,
            'updated_at' => $this->clock->now(),
        ]) !== 1) {
            throw new InvalidArgumentException('Community proposal is missing, foreign, or not awaiting approval.');
        }
    }

    public function rejectProposal(TenantContext $actor, string $itemId): void
    {
        $this->permit($actor, PermissionCatalog::COMMUNITY_MODERATE);

        $query = $this->scope($actor)->where('id', $itemId)
            ->where('proposal_state', CommunityProposalState::Proposed->value);
        if ($query->update([
            'proposal_state' => CommunityProposalState::Rejected->value,
            'updated_at' => $this->clock->now(),
        ]) !== 1) {
            throw new InvalidArgumentException('Community proposal is missing, foreign, or not awaiting moderation.');
        }
    }

    /** @param array<string, mixed> $changes */
    private function mutate(TenantContext $actor, string $itemId, array $changes): void
    {
        if (trim($itemId) === '') {
            throw new InvalidArgumentException('Community item identity is required.');
        }
        $changes['updated_at'] = $this->clock->now();
        if ($this->scope($actor)->where('id', $itemId)->update($changes) !== 1) {
            throw new AuthorizationException('Community item is missing or outside the active tenant scope.');
        }
    }

    private function permit(TenantContext $actor, string $permission): void
    {
        if (! $this->access->allows($actor, $permission)
            || ! $this->database->table('workspaces')->where('id', $actor->workspaceId)
                ->where('organization_id', $actor->organizationId)->exists()
            || ($actor->brandId !== null && ! $this->database->table('brands')->where('id', $actor->brandId)
                ->where('workspace_id', $actor->workspaceId)->exists())) {
            throw new AuthorizationException('Community workspace authority denied.');
        }
    }

    private function scope(TenantContext $actor): Builder
    {
        return $this->database->table('community_items')
            ->where('workspace_id', $actor->workspaceId)
            ->when($actor->brandId !== null, fn (Builder $query) => $query->where('brand_id', $actor->brandId));
    }
}
