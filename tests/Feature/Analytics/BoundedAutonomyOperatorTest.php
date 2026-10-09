<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsReports;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

function boundedAutonomyReport(AnalyticsFixture $fixture): array
{
    $facts = app(AnalyticsFacts::class);
    $facts->project($fixture->actor, $fixture->event());

    return app(AnalyticsReports::class)->generate(
        $fixture->actor, 'counts', new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'),
    );
}

it('issues a scoped offline preview from an authorized measured report without granting execution', function () {
    $this->withoutVite();
    $fixture = new AnalyticsFixture;
    app(WorkspaceRoleManager::class)->grantPermission($fixture->role, PermissionCatalog::AI_EXECUTE);
    $report = boundedAutonomyReport($fixture);
    $base = '/workspaces/'.$fixture->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($fixture->actor->actorId))->withHeader('X-Brand-Id', $fixture->actor->brandId)
        ->post($base.'/autonomy/preview', ['report_id' => $report['id'], 'target_count' => 12])
        ->assertRedirect()->assertSessionHas('analytics_notice', 'autonomy_preview_created');
    expect(DB::table('idempotency_keys')->where('workspace_id', $fixture->actor->workspaceId)
        ->where('scope', 'ai-offline-autonomy-preview:v1')->count())->toBe(1);

    $this->get($base)->assertOk()->assertInertia(fn (Assert $p) => $p->component('analytics/operator')
        ->where('offline_autonomy_preview.status', 'preview_ready')
        ->where('offline_autonomy_preview.execution_authorized', false)
        ->where('offline_autonomy_preview.actions.0.tool_id', 'analytics_read')
        ->where('offline_autonomy_preview.actions.0.effect', 'read')
        ->where('offline_autonomy_preview.stages.execute', 'disabled')
        ->has('autonomy_report_options', 1));
});

it('denies non-AI users and arbitrary report references before any offline run is persisted', function () {
    $this->withoutVite();
    $fixture = new AnalyticsFixture;
    $report = boundedAutonomyReport($fixture);
    $base = '/workspaces/'.$fixture->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($fixture->actor->actorId))->withHeader('X-Brand-Id', $fixture->actor->brandId)
        ->post($base.'/autonomy/preview', ['report_id' => $report['id'], 'target_count' => 12])->assertForbidden();
    expect(DB::table('idempotency_keys')->where('workspace_id', $fixture->actor->workspaceId)
        ->where('scope', 'ai-offline-autonomy-preview:v1')->count())->toBe(0);
    $this->get($base)->assertOk()->assertInertia(fn (Assert $p) => $p->where('autonomy_report_options', [])
        ->where('offline_autonomy_preview', null));

    app(WorkspaceRoleManager::class)->grantPermission($fixture->role, PermissionCatalog::AI_EXECUTE);
    $this->post($base.'/autonomy/preview', ['report_id' => $report['id'], 'target_count' => 0])->assertUnprocessable();
    $this->post($base.'/autonomy/preview', ['report_id' => (string) \Illuminate\Support\Str::uuid(), 'target_count' => 12])
        ->assertRedirect()->assertSessionHas('analytics_notice', 'autonomy_preview_denied');
    expect(DB::table('idempotency_keys')->where('workspace_id', $fixture->actor->workspaceId)
        ->where('scope', 'ai-offline-autonomy-preview:v1')->count())->toBe(0);
});

it('rechecks purpose and tenant ownership before displaying a flashed proposal', function () {
    $this->withoutVite();
    $fixture = new AnalyticsFixture;
    app(WorkspaceRoleManager::class)->grantPermission($fixture->role, PermissionCatalog::AI_EXECUTE);
    $report = boundedAutonomyReport($fixture);
    $base = '/workspaces/'.$fixture->actor->workspaceId.'/analytics';
    $this->actingAs(User::findOrFail($fixture->actor->actorId))->withHeader('X-Brand-Id', $fixture->actor->brandId)
        ->post($base.'/autonomy/preview', ['report_id' => $report['id'], 'target_count' => 12])
        ->assertSessionHas('analytics_notice', 'autonomy_preview_created');
    $fixture->consent(ConsentDecision::Denied, '2026-10-03T11:00:00Z');
    $this->get($base)->assertOk()->assertInertia(fn (Assert $p) => $p->where('state', 'ready')
        ->where('offline_autonomy_preview', null)->where('autonomy_report_options', []));
    $this->post($base.'/autonomy/preview', ['report_id' => $report['id'], 'target_count' => 12])
        ->assertRedirect()->assertSessionHas('analytics_notice', 'autonomy_preview_denied');
});
