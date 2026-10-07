<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Application\Community\CommunityInbox;
use App\Modules\Providers\Domain\Community\CommunityInboundVerifier;
use App\Modules\Providers\Domain\Community\CommunityItem;
use App\Modules\Providers\Domain\Community\CommunityItemType;
use App\Modules\Providers\Domain\Community\CommunityModerationState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function task0078Fixture(): AnalyticsFixture
{
    $fixture = new AnalyticsFixture;
    $roles = app(WorkspaceRoleManager::class);
    foreach ([
        PermissionCatalog::COMMUNITY_READ,
        PermissionCatalog::COMMUNITY_MODERATE,
        PermissionCatalog::AI_EXECUTE,
        PermissionCatalog::AI_APPROVE,
    ] as $permission) {
        $roles->grantPermission($fixture->role, $permission);
    }

    return $fixture;
}

it('fails closed without an explicit inbound verifier', function () {
    $f = task0078Fixture();
    $item = new CommunityItem(
        $f->actor->workspaceId,
        'linkedin',
        'comment-1',
        CommunityItemType::Comment,
        'author-raw',
        'Hello',
        'https://provider.test/comments/1',
    );

    expect(fn () => app(CommunityInbox::class)->admit($f->actor, $item, new DateTimeImmutable('2026-10-02T10:00:00Z')))
        ->toThrow(RuntimeException::class)
        ->and(DB::table('community_items')->count())->toBe(0);
});

it('persists replay-safe tenant-scoped community evidence and keeps AI responses operator-approved', function () {
    $f = task0078Fixture();
    app()->bind(CommunityInboundVerifier::class, fn () => new class implements CommunityInboundVerifier
    {
        public function verify(TenantContext $actor, CommunityItem $item): ?string
        {
            return $actor->workspaceId === $item->tenantId ? 'verified:'.$item->providerKey.':'.$item->externalId : null;
        }
    });

    $service = app(CommunityInbox::class);
    $rawAuthor = 'provider-user-opaque-123';
    $rawProvenance = 'https://provider.test/comments/1?opaque=do-not-store';
    $item = new CommunityItem(
        $f->actor->workspaceId,
        'linkedin',
        'comment-1',
        CommunityItemType::Comment,
        $rawAuthor,
        'Please help with this post.',
        $rawProvenance,
    );

    expect($service->admit($f->actor, $item, new DateTimeImmutable('2026-10-02T10:00:00Z')))->toBe('admitted')
        ->and($service->admit($f->actor, $item, new DateTimeImmutable('2026-10-02T10:00:00Z')))->toBe('replayed')
        ->and($service->admit($f->actor, new CommunityItem(
            $f->actor->workspaceId,
            'linkedin',
            'comment-1',
            CommunityItemType::Comment,
            $rawAuthor,
            'Changed provider evidence',
            $rawProvenance,
        ), new DateTimeImmutable('2026-10-02T10:00:00Z')))->toBe('conflict')
        ->and(DB::table('community_items')->count())->toBe(1);

    $stored = json_encode(DB::table('community_items')->first(), JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain($rawAuthor)->not->toContain($rawProvenance);

    $id = (string) DB::table('community_items')->value('id');
    $service->assign($f->actor, $id, $f->actor->actorId);
    $service->moderate($f->actor, $id, CommunityModerationState::Resolved);
    $service->propose($f->actor, $id, 'Draft response for operator review.');

    expect(DB::table('community_items')->value('proposal_state'))->toBe('proposed');

    $service->approveProposal($f->actor, $id);
    $row = $service->recent($f->actor)[0];

    expect($row['moderation_state'])->toBe('resolved')
        ->and($row['assigned_actor_id'])->toBe($f->actor->actorId)
        ->and($row['proposal_state'])->toBe('approved')
        ->and($row['proposal_text'])->toBe('Draft response for operator review.')
        ->and($row)->not->toHaveKey('author_external_id')
        ->and($row)->not->toHaveKey('provenance_url');
});

it('rejects foreign tenant items assignments and moderation without current authority', function () {
    $f = task0078Fixture();
    $other = new AnalyticsFixture;
    app()->bind(CommunityInboundVerifier::class, fn () => new class implements CommunityInboundVerifier
    {
        public function verify(TenantContext $actor, CommunityItem $item): ?string
        {
            return 'verified';
        }
    });

    $service = app(CommunityInbox::class);
    $foreign = new CommunityItem(
        $other->actor->workspaceId,
        'linkedin',
        'foreign-1',
        CommunityItemType::Mention,
        'author',
        'Foreign',
        'https://provider.test/foreign/1',
    );

    expect(fn () => $service->admit($f->actor, $foreign, new DateTimeImmutable('2026-10-02T10:00:00Z')))
        ->toThrow(AuthorizationException::class);

    DB::table('workspace_role_permissions')
        ->where('workspace_role_id', $f->role)
        ->where('permission', PermissionCatalog::COMMUNITY_MODERATE)
        ->delete();

    $owned = new CommunityItem(
        $f->actor->workspaceId,
        'linkedin',
        'owned-1',
        CommunityItemType::Message,
        'author',
        'Owned',
        'https://provider.test/owned/1',
    );
    expect($service->admit($f->actor, $owned, new DateTimeImmutable('2026-10-02T10:00:00Z')))->toBe('admitted');
    $id = (string) DB::table('community_items')->value('id');

    expect(fn () => $service->moderate($f->actor, $id, CommunityModerationState::Hidden))
        ->toThrow(AuthorizationException::class);
});
