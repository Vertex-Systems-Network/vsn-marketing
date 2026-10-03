<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Analytics\Domain\RevenueDefinition;
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
    $schema = 'analytics_test_'.bin2hex(random_bytes(8));
    DB::statement('CREATE SCHEMA '.$schema);
    config(['analytics.test_schema' => $schema, 'analytics.test_search_path' => config('database.connections.pgsql.search_path')]);
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge();
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $schema = config('analytics.test_schema');
    if (! is_string($schema) || ! preg_match('/^analytics_test_[a-f0-9]{16}$/', $schema)) {
        return;
    }
    config(['database.connections.pgsql.search_path' => config('analytics.test_search_path')]);
    DB::purge();
    // Drop only this synthetic namespace; canonical append-only triggers remain intact.
    DB::statement('DROP SCHEMA '.$schema.' CASCADE');
});

it('serializes competing canonical analytics admissions on PostgreSQL', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $fixture = new AnalyticsFixture;
    $event = $fixture->event('postgres-source');
    $service = app(AnalyticsFacts::class);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->toBeArray();
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exit = 1;
        try {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            if (fread($pair[1], 1) !== 'S') {
                throw new RuntimeException('Missing parent signal.');
            }
            $result = $service->project($fixture->actor, $event);
            $exit = fwrite($pair[1], $result) === strlen($result) ? 0 : 1;
        } catch (Throwable) {
            $exit = 1;
        } finally {
            fclose($pair[1]);
            exit($exit);
        }
    }
    fclose($pair[1]);
    try {
        DB::beginTransaction();
        expect($service->project($fixture->actor, $event))->toBe('admitted');
        fwrite($pair[0], 'S');
        usleep(150000);
        DB::commit();
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 8))->toBe('replayed');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('analytics_facts')->where('event_id', $event)->count())->toBe(1)
            ->and($service->project($fixture->actor, $fixture->event('postgres-source')))->toBe('conflict')
            ->and(DB::table('analytics_conflicts')->where('workspace_id', $fixture->actor->workspaceId)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
    }
});

it('persists reproducible behavior snapshots with late-arrival lineage on PostgreSQL', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $s->project($f->actor, $f->event('entry'));
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created']);
    $start = new DateTimeImmutable('2026-10-02Z');
    $end = new DateTimeImmutable('2026-10-03Z');
    $prior = $s->behaviorSnapshot($f->actor, $d, $start, $end, $f->now());
    expect(array_column($prior['result']['steps'], 'subjects'))->toBe([1, 0]);
    $f->time = $f->time->modify('+1 hour');
    $s->project($f->actor, $f->event('late', '2026-10-02T10:30:00Z', '2026-10-03T12:30:00Z', 'cart.created'));
    $new = $s->behaviorSnapshot($f->actor, $d, $start, $end, $f->now());
    expect(array_column($new['result']['steps'], 'subjects'))->toBe([1, 1])
        ->and($s->readSnapshot($f->actor, $prior['id']))->toBe($prior)
        ->and($s->readSnapshot($f->actor, $new['id']))->toBe($new)
        ->and(DB::table('analytics_snapshots')->count())->toBe(2);
});

it('reconciles retained revenue identities and late refunds reproducibly on PostgreSQL', function () {
    $f = new AnalyticsFixture;
    $s = app(AnalyticsFacts::class);
    $payload = ['transaction_id' => 'pg-purchase', 'amount_minor' => 101, 'currency' => 'USD', 'currency_exponent' => 2];
    foreach (['purchase', 'semantic-replay'] as $source) {
        $s->project($f->actor, $f->event($source, type: 'order.completed', payload: $payload));
    }
    $d = new RevenueDefinition;
    $start = new DateTimeImmutable('2026-10-02Z');
    $end = new DateTimeImmutable('2026-10-03Z');
    $prior = $s->revenueSnapshot($f->actor, $d, $start, $end, $f->now());
    expect($prior['result']['currencies']['USD']['net'])->toBe(101)
        ->and($prior['result']['quality']['duplicate_money'])->toBe(1);
    $f->time = $f->time->modify('+1 hour');
    $s->project($f->actor, $f->event('late-refund', '2026-10-02T10:30:00Z', '2026-10-03T12:30:00Z',
        'order.refunded', [...$payload, 'amount_minor' => 31, 'refund_id' => 'pg-refund']));
    $next = $s->revenueSnapshot($f->actor, $d, $start, $end, $f->now());
    expect($next['result']['currencies']['USD']['net'])->toBe(70)
        ->and($s->readSnapshot($f->actor, $prior['id']))->toBe($prior)
        ->and($s->readSnapshot($f->actor, $next['id']))->toBe($next);
});
