<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\Community\CommunityItem;
use App\Modules\Providers\Domain\Community\CommunityItemType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0078CommunityBoundaryTest extends TestCase
{
    public function test_comment_mention_and_message_keep_provider_provenance_and_tenant_identity(): void
    {
        foreach (CommunityItemType::cases() as $type) {
            $item = new CommunityItem(
                'workspace-a',
                'provider-a',
                'external-'.$type->value,
                $type,
                'provider-author-1',
                'Operator-visible inbound content',
                'https://provider.test/items/'.$type->value,
            );

            self::assertSame('workspace-a', $item->tenantId);
            self::assertSame($type, $item->type);
            self::assertStringStartsWith('https://', $item->provenanceUrl);
        }
    }

    public function test_empty_identity_or_non_https_provenance_fails_closed(): void
    {
        foreach ([
            ['', 'provider', 'external', 'author', 'body', 'https://provider.test/item'],
            ['workspace', 'provider', 'external', 'author', 'body', 'http://provider.test/item'],
        ] as [$workspace, $provider, $external, $author, $body, $url]) {
            try {
                new CommunityItem($workspace, $provider, $external, CommunityItemType::Comment, $author, $body, $url);
                self::fail('Invalid community item was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
