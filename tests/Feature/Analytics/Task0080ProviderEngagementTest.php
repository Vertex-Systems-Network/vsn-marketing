<?php

use App\Modules\Analytics\Application\ProviderEngagementAnalytics;
use App\Modules\Analytics\Domain\ProviderEngagementSourceVerifier;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Analytics\EngagementFact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('admits bounded provider aggregates without storing raw lineage and renders source-specific operator evidence', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    app()->bind(ProviderEngagementSourceVerifier::class, fn () => new class implements ProviderEngagementSourceVerifier
    {
        public function verify(TenantContext $actor, EngagementFact $fact): ?string
        {
            return $actor->workspaceId === $fact->tenantId ? 'verified:'.$fact->sourceLineage : null;
        }
    });
    $service = app(ProviderEngagementAnalytics::class);
    $lineage = 'https://provider.test/post/abc?opaque-secret=do-not-store';
    $fact = new EngagementFact(
        $f->actor->workspaceId,
        'linkedin',
        'post.impressions',
        42,
        $lineage,
        '2026-10-02T10:00:00Z',
        true,
        '2026-10-02T10:05:00Z',
        'count',
        1,
        'LinkedIn provider-defined impressions; not cross-provider equivalent.',
        $f->actor->brandId,
    );

    expect($service->admit($f->actor, $fact))->toBe('admitted')
        ->and($service->admit($f->actor, $fact))->toBe('replayed')
        ->and($service->admit($f->actor, new EngagementFact(
            $f->actor->workspaceId, 'linkedin', 'post.impressions', 43, $lineage,
            '2026-10-02T10:00:00Z', true, '2026-10-02T10:05:00Z', 'count', 1,
            'LinkedIn provider-defined impressions; not cross-provider equivalent.', $f->actor->brandId,
        )))->toBe('conflict')
        ->and(DB::table('provider_engagement_facts')->count())->toBe(1)
        ->and(json_encode(DB::table('provider_engagement_facts')->first(), JSON_THROW_ON_ERROR))->not->toContain($lineage);

    $recent = $service->recent($f->actor);
    expect($recent)->toHaveCount(1)
        ->and($recent[0]['source_completeness'])->toBe('unknown')
        ->and($recent[0]['missing_provider_events'])->toBe('unknown')
        ->and($recent[0]['receipt_lag_seconds'])->toBe(300)
        ->and($recent[0]['delayed'])->toBeTrue()
        ->and($recent[0]['definition']['cross_provider_equivalent'])->toBeFalse();

    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get('/workspaces/'.$f->actor->workspaceId.'/analytics')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('provider_engagement', 1)
            ->where('provider_engagement.0.provider_key', 'linkedin')
            ->where('provider_engagement.0.metric', 'post.impressions')
            ->where('provider_engagement.0.value', 42)
            ->where('provider_engagement.0.source_completeness', 'unknown')
            ->where('provider_engagement.0.definition.cross_provider_equivalent', false)
            ->missing('provider_engagement.0.source_lineage'));

    config(['analytics.purpose_approved' => false]);
    $this->get('/workspaces/'.$f->actor->workspaceId.'/analytics')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state', 'purpose_unavailable')->where('provider_engagement', []));
});

it('rejects foreign workspace provider aggregates before persistence', function () {
    $f = new AnalyticsFixture;
    $other = new AnalyticsFixture;
    $fact = new EngagementFact(
        $other->actor->workspaceId, 'linkedin', 'post.impressions', 1, 'foreign-lineage',
        '2026-10-02T10:00:00Z', false, '2026-10-02T10:01:00Z', brandId: $other->actor->brandId,
    );

    expect(fn () => app(ProviderEngagementAnalytics::class)->admit($f->actor, $fact))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('provider_engagement_facts')->count())->toBe(0);
});
