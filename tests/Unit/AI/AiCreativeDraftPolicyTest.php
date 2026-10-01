<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\AiCreativeCatalog;
use App\Modules\AI\Application\AiCreativeReviewGate;
use App\Modules\AI\Domain\AiCreativeDraftPolicy;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiCreativePolicyAuthority;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiCreativeDraftPolicyTest extends TestCase
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV5sAAAAASUVORK5CYII=';

    private function definition(string $capability = 'creative_text'): array
    {
        return (new AiCreativeCatalog(dirname(__DIR__, 3)))->resolve($capability, 'v1');
    }

    private function scope(string $actor = 'creator'): TenantContext
    {
        return new TenantContext('org-a', 'workspace-a', 'brand-a', $actor);
    }

    private function providerResult(array $override = []): array
    {
        return ['status' => 'complete', 'schema_id' => 'creative_candidate.v1', 'provider_request_id' => 'offline-request-a',
            'output' => array_replace(['workspace_id' => 'workspace-a', 'reference_ids' => ['brand-source'],
                'text' => 'A reviewable brand draft.', 'media' => []], $override)];
    }

    private function draft(string $capability = 'creative_text'): array
    {
        $media = $capability === 'creative_image' ? [['mime_type' => 'image/png', 'bytes' => base64_decode(self::PNG, true)]] : [];

        return (new AiCreativeDraftPolicy)->validate($this->providerResult(['media' => $media]), $this->definition($capability), $this->scope(), ['brand-source'], 'rights-a', str_repeat('a', 64));
    }

    public function test_text_and_image_are_disclosed_hash_bound_drafts_with_unverified_content_credentials(): void
    {
        foreach (['creative_text', 'creative_image'] as $capability) {
            $draft = $this->draft($capability);
            self::assertSame('draft', $draft['status']);
            self::assertSame('pending_independent_review', $draft['review_status']);
            self::assertSame('unverified', $draft['content_credentials']);
            self::assertSame('AI-generated draft; independent review required.', $draft['disclosure']);
            self::assertSame(AiCreativeDraftPolicy::hash($draft), $draft['candidate_sha256']);
            self::assertSame('rights-a', $draft['rights_reference']);
        }
        $media = $this->draft('creative_image')['media'][0];
        self::assertSame(hash('sha256', base64_decode(self::PNG, true)), $media['sha256']);
        self::assertSame(1, $media['width']);
    }

    public function test_malformed_foreign_unknown_unbounded_unsafe_and_model_approval_outputs_deny(): void
    {
        $cases = [
            ['workspace_id' => 'workspace-b'], ['reference_ids' => ['invented']], ['approved' => true],
            ['text' => 'https://attacker.example/exfil'], ['text' => 'api_key: stolen'],
            ['text' => str_repeat('a', 4097)], ['media' => [['url' => 'https://attacker.example/image']]],
        ];
        foreach ($cases as $override) {
            try {
                (new AiCreativeDraftPolicy)->validate($this->providerResult($override), $this->definition(), $this->scope(), ['brand-source'], 'rights-a', str_repeat('a', 64));
                self::fail('Invalid creative output accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['mime_type' => 'image/svg+xml', 'bytes' => '<svg/>'], ['mime_type' => 'image/png', 'bytes' => 'broken'], ['mime_type' => 'image/png', 'bytes' => str_repeat('a', 262145)]] as $asset) {
            try {
                (new AiCreativeDraftPolicy)->validate($this->providerResult(['media' => [$asset]]), $this->definition('creative_image'), $this->scope(), ['brand-source'], 'rights-a', str_repeat('a', 64));
                self::fail('Invalid media accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_independent_review_requires_every_current_exact_bound_review_and_permission_and_never_publishes(): void
    {
        $draft = $this->draft();
        $authority = new class($draft['candidate_sha256']) implements AiCreativePolicyAuthority
        {
            public array $reviews = ['rights' => 'rights-reviewer', 'brand' => 'brand-reviewer', 'safety' => 'safety-reviewer'];

            public function __construct(private readonly string $hash) {}

            public function allowsInput(TenantContext $scope, string $requestHash, string $rightsReference): bool
            {
                return false;
            }

            public function reviewers(TenantContext $scope, string $candidateHash): array
            {
                return $candidateHash === $this->hash ? $this->reviews : [];
            }
        };
        $permissions = new class implements AiContextPermission
        {
            public bool $allowed = true;

            public function allows(TenantContext $scope, string $permission): bool
            {
                return $this->allowed;
            }
        };
        $gate = new AiCreativeReviewGate($authority, $permissions);
        self::assertFalse($gate->review($this->scope('review-operator'), $draft)['publication_authorized']);
        foreach ([['rights' => 'rights-reviewer'], ['rights' => 'creator', 'brand' => 'brand-reviewer', 'safety' => 'safety-reviewer']] as $reviews) {
            $authority->reviews = $reviews;
            try {
                $gate->review($this->scope('review-operator'), $draft);
                self::fail('Missing/self review accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $authority->reviews = ['rights' => 'r', 'brand' => 'b', 'safety' => 's'];
        $mutated = $draft;
        $mutated['text'] = 'Changed after approval.';
        $mutated['candidate_sha256'] = AiCreativeDraftPolicy::hash($mutated);
        try {
            $gate->review($this->scope('review-operator'), $mutated);
            self::fail('Changed candidate accepted by old reviews.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $permissions->allowed = false;
        $this->expectException(InvalidArgumentException::class);
        $gate->review($this->scope('review-operator'), $draft);
    }

    public function test_unknown_video_and_mutable_version_are_unavailable(): void
    {
        foreach ([['creative_video', 'v1'], ['creative_image', 'latest']] as [$capability, $version]) {
            try {
                (new AiCreativeCatalog(dirname(__DIR__, 3)))->resolve($capability, $version);
                self::fail('Unsupported capability/version accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
