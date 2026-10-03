<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\ScheduledAnalyticsReports;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('generates one durable daily snapshot and replays safely without duplicates', function () {
    $f = new AnalyticsFixture;
    $s = app(ScheduledAnalyticsReports::class);
    app(AnalyticsFacts::class)->project($f->actor, $f->event());
    $id = $s->create($f->actor, 'counts');
    $f->time = new DateTimeImmutable('2026-10-04T00:00:01Z');
    expect($s->run($id))->toBeTrue()->and($s->run($id))->toBeFalse()
        ->and(DB::table('analytics_report_runs')->count())->toBe(1)
        ->and(DB::table('analytics_snapshots')->count())->toBe(1);
    $run = DB::table('analytics_report_runs')->first();
    expect($run->status)->toBe('complete')->and($run->attempts)->toBe(1)
        ->and(app(AnalyticsFacts::class)->readSnapshot($f->actor, $run->snapshot_id)['start_utc'])->toBe('2026-10-03T00:00:00+00:00');
});

it('recovers an expired claim and retains bounded retry evidence after authorization revocation', function () {
    $f = new AnalyticsFixture;
    $s = app(ScheduledAnalyticsReports::class);
    $id = $s->create($f->actor, 'counts');
    $f->time = new DateTimeImmutable('2026-10-04T00:00:01Z');
    DB::table('analytics_report_runs')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $f->actor->workspaceId,
        'schedule_id' => $id, 'window_end' => new DateTimeImmutable('2026-10-04T00:00:00Z'), 'status' => 'running', 'attempts' => 1,
        'claim_token' => (string) Str::uuid(), 'lease_until' => new DateTimeImmutable('2026-10-03T23:59:00Z'), 'created_at' => $f->now()]);
    expect($s->run($id))->toBeTrue()->and(DB::table('analytics_report_runs')->value('attempts'))->toBe(2);
    $next = $s->create($f->actor, 'counts');
    DB::table('workspace_role_permissions')->where('workspace_role_id', $f->role)
        ->where('permission', PermissionCatalog::ANALYTICS_READ)->delete();
    $f->time = new DateTimeImmutable('2026-10-05T00:00:01Z');
    for ($i = 0; $i < 4; $i++) {
        expect($s->run($next))->toBeFalse();
    }
    $run = DB::table('analytics_report_runs')->where('schedule_id', $next)->first();
    expect($run->status)->toBe('failed')->and($run->attempts)->toBe(3)
        ->and($run->failure_code)->toBe('retry_budget_exhausted')
        ->and(DB::table('analytics_report_schedules')->where('id', $next)->value('enabled'))->toBeFalsy()
        ->and(DB::table('analytics_snapshots')->count())->toBe(1);
});

it('denies missing purpose, foreign owner disable and frozen definition drift', function () {
    $f = new AnalyticsFixture;
    $s = app(ScheduledAnalyticsReports::class);
    $id = $s->create($f->actor, 'counts');
    $other = new AnalyticsFixture;
    expect(fn () => $s->disable($other->actor, $id))->toThrow(AuthorizationException::class);
    DB::table('analytics_report_schedules')->where('id', $id)->update(['definition_hash' => str_repeat('a', 64)]);
    $f->time = new DateTimeImmutable('2026-10-04T00:00:01Z');
    expect($s->run($id))->toBeFalse()->and(DB::table('analytics_snapshots')->count())->toBe(0);
    config(['analytics.purpose_approved' => false]);
    expect(fn () => $s->create($f->actor, 'counts'))->toThrow(AuthorizationException::class);
});

it('replays schedule DDL and refuses destructive rollback once evidence exists', function () {
    $migration = require database_path('migrations/2026_10_03_000002_create_analytics_report_schedules.php');
    $migration->up();
    $migration->down();
    $migration->down();
    $migration->up();
    Schema::drop('analytics_report_runs');
    $migration->up();
    expect(Schema::hasTable('analytics_report_runs'))->toBeTrue();
    $f = new AnalyticsFixture;
    app(ScheduledAnalyticsReports::class)->create($f->actor, 'counts');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('analytics_report_schedules')->count())->toBe(1);
});

it('denies an unknown partial schedule schema instead of marking it applied', function () {
    $migration = require database_path('migrations/2026_10_03_000002_create_analytics_report_schedules.php');
    $migration->down();
    Schema::create('analytics_report_schedules', fn ($table) => $table->uuid('id')->primary());
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});
