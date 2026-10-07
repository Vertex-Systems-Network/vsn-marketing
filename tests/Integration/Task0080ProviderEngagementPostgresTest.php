<?php

use App\Modules\Analytics\Application\ProviderEngagementAnalytics;
use App\Modules\Analytics\Domain\ProviderEngagementSourceVerifier;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Analytics\EngagementFact;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsFixture;

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment required.');
    }
    if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
        throw new RuntimeException('Disposable test database required.');
    }
    $schema = 'task0080_test_'.bin2hex(random_bytes(8));
    DB::statement('CREATE SCHEMA '.$schema);
    config(['task0080.test_schema' => $schema, 'task0080.test_search_path' => config('database.connections.pgsql.search_path')]);
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge();
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $schema = config('task0080.test_schema');
    if (! is_string($schema) || ! preg_match('/^task0080_test_[a-f0-9]{16}$/', $schema)) {
        return;
    }
    config(['database.connections.pgsql.search_path' => config('task0080.test_search_path')]);
    DB::purge();
    DB::statement('DROP SCHEMA '.$schema.' CASCADE');
});

it('persists replay-safe source-specific provider engagement evidence on PostgreSQL', function () {
    $f = new AnalyticsFixture;
    app()->bind(ProviderEngagementSourceVerifier::class, fn () => new class implements ProviderEngagementSourceVerifier
    {
        public function verify(TenantContext $actor, EngagementFact $fact): ?string
        {
            return $actor->workspaceId === $fact->tenantId ? 'verified:'.$fact->sourceLineage : null;
        }
    });
    $service = app(ProviderEngagementAnalytics::class);
    $lineage = 'provider-object-123';
    $fact = new EngagementFact(
        $f->actor->workspaceId, 'linkedin', 'post.impressions', 250, $lineage,
        '2026-10-02T10:00:00Z', true, '2026-10-02T10:09:00Z', 'count', 1,
        'Provider-defined impressions; no cross-provider equivalence.', $f->actor->brandId,
    );

    expect($service->admit($f->actor, $fact))->toBe('admitted')
        ->and($service->admit($f->actor, $fact))->toBe('replayed')
        ->and($service->admit($f->actor, new EngagementFact(
            $f->actor->workspaceId, 'linkedin', 'post.impressions', 251, $lineage,
            '2026-10-02T10:00:00Z', true, '2026-10-02T10:09:00Z', 'count', 1,
            'Provider-defined impressions; no cross-provider equivalence.', $f->actor->brandId,
        )))->toBe('conflict')
        ->and(DB::table('provider_engagement_facts')->count())->toBe(1)
        ->and((string) DB::table('provider_engagement_facts')->value('source_lineage_hash'))->toBe(hash('sha256', 'verified:'.$lineage));

    $row = $service->recent($f->actor)[0];
    expect($row['value'])->toBe(250)
        ->and($row['receipt_lag_seconds'])->toBe(540)
        ->and($row['provider_total_status'])->toBe('provider_reported_total')
        ->and($row['source_completeness'])->toBe('unknown')
        ->and($row['definition']['cross_provider_equivalent'])->toBeFalse();
});
