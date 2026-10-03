<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsQuality;
use App\Modules\Analytics\Application\ScheduledAnalyticsReports;
use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Analytics\Domain\RevenueDefinition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
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

it('measures bounded analytics request latency queries memory and overflow on PostgreSQL', function () {
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $quality = app(AnalyticsQuality::class);
    $start = new DateTimeImmutable('2026-10-02Z');
    $end = new DateTimeImmutable('2026-10-03Z');
    $stats = (object) ['enabled' => false, 'queries' => 0, 'query_ms' => 0.0];
    DB::listen(static function (QueryExecuted $query) use ($stats): void {
        if ($stats->enabled) {
            $stats->queries++;
            $stats->query_ms += $query->time;
        }
    });
    $samples = $setup = $summaries = [];
    $seeded = 0;
    foreach ([10, 100, AnalyticsFacts::MAX_FACTS] as $size) {
        $began = hrtime(true);
        for (; $seeded < $size; $seeded++) {
            expect($facts->project($f->actor, $f->event('scale-'.$seeded)))->toBe('admitted');
        }
        $setup[$size] = (hrtime(true) - $began) / 1e6;
        foreach (['count_snapshot', 'source_quality'] as $operation) {
            $latencies = [];
            for ($sample = 0; $sample < 5; $sample++) {
                $stats->queries = 0;
                $stats->query_ms = 0.0;
                memory_reset_peak_usage();
                $baseline = memory_get_usage(true);
                $stats->enabled = true;
                $began = hrtime(true);
                try {
                    $r = $operation === 'count_snapshot'
                        ? $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), $start, $end, $f->now())
                        : $quality->reconcile($f->actor, 'scale-'.$size.'-'.$sample, 'fixture', 'product.viewed', $start, $end, $f->now());
                } finally {
                    $stats->enabled = false;
                }
                $elapsed = (hrtime(true) - $began) / 1e6;
                $peak = max(0, memory_get_peak_usage(true) - $baseline);
                $latencies[] = $elapsed;
                $samples[] = ['operation' => $operation, 'events' => $size, 'sample' => $sample + 1,
                    'latency_ms' => $elapsed, 'queries' => $stats->queries, 'query_ms' => $stats->query_ms,
                    'incremental_peak_bytes' => $peak, 'fingerprint' => $r['fingerprint']];
                expect($elapsed)->toBeLessThanOrEqual(30000.0)
                    ->and($stats->queries)->toBeLessThanOrEqual(50 * $size + 200)
                    ->and($peak)->toBeLessThanOrEqual(128 * 1024 * 1024)
                    ->and($r['publication_authorized'])->toBeFalse();
                if ($operation === 'count_snapshot') {
                    expect($r['value'])->toBe($size)->and($r['source_completeness'])->toBe('unknown');
                } else {
                    expect($r['observed_keys'])->toBe($size)->and($r['missing_projection'])->toBe(0)
                        ->and($r['expected_total'])->toBeNull()->and($r['coverage'])->toBe('unknown')
                        ->and($quality->reconcile($f->actor, 'scale-'.$size.'-'.$sample, 'fixture', 'product.viewed', $start, $end, $f->now()))->toBe($r);
                }
            }
            sort($latencies);
            $summaries[] = ['operation' => $operation, 'events' => $size, 'samples' => 5,
                'p50_ms' => $latencies[2], 'p95_ms' => $latencies[4], 'p99_ms' => $latencies[4],
                'percentile_method' => 'nearest_rank_five_samples_not_production_tail_estimate'];
        }
    }
    $facts->project($f->actor, $f->event('overflow-1001'));
    $snapshots = DB::table('analytics_snapshots')->count();
    $checks = DB::table('analytics_reconciliations')->count();
    expect(fn () => $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), $start, $end, $f->now()))->toThrow(RuntimeException::class)
        ->and(fn () => $quality->reconcile($f->actor, 'overflow', 'fixture', 'product.viewed', $start, $end, $f->now()))->toThrow(RuntimeException::class)
        ->and(DB::table('analytics_snapshots')->count())->toBe($snapshots)
        ->and(DB::table('analytics_reconciliations')->count())->toBe($checks);
    $source = getenv('TARGET_SHA');
    if (! is_string($source) || ! preg_match('/^[a-f0-9]{40}$/', $source)) {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->mustRun();
        $source = trim($process->getOutput());
    }
    $hashes = [];
    foreach (['app/Modules/Analytics/Application/AnalyticsFacts.php', 'app/Modules/Analytics/Application/AnalyticsQuality.php',
        'app/Modules/Analytics/Infrastructure/ConsentAnalyticsPrivacy.php', 'tests/Support/AnalyticsFixture.php',
        'tests/Integration/AnalyticsFactsPostgresTest.php', '.github/workflows/application-ci.yml'] as $path) {
        $hashes[$path] = hash_file('sha256', base_path($path));
    }
    $cpu = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
    preg_match('/^model name\s*:\s*(.+)$/m', $cpu, $model);
    preg_match_all('/^processor\s*:/m', $cpu, $processors);
    $evidence = ['schema_version' => 1, 'task' => 'TASK-0074', 'source_sha' => $source, 'file_sha256' => $hashes,
        'environment' => ['php' => PHP_VERSION, 'postgres' => DB::selectOne('SHOW server_version')->server_version,
            'laravel' => app()->version(), 'os' => PHP_OS_FAMILY, 'cpu_model' => $model[1] ?? null,
            'logical_cpus' => count($processors[0]), 'load_average' => sys_getloadavg()],
        'profile' => 'one synthetic consented contact, one workspace/brand, canonical received/projected events; loopback PostgreSQL; no provider',
        'budgets' => ['per_operation_ms' => 30000, 'queries' => '50*events+200', 'incremental_peak_bytes' => 134217728],
        'setup_ms_by_cardinality' => $setup, 'raw_samples' => $samples, 'summaries' => $summaries,
        'overflow_1001' => 'both operations refused; no partial snapshot/checkpoint',
        'production_slo_certified' => false, 'production_capacity_certified' => false,
        'excluded' => ['HTTP/browser latency', 'provider latency', 'multi-tenant production load', 'scheduler saturation', 'infrastructure cost']];
    $json = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app'));
    file_put_contents(storage_path('app/phase12-certification-samples.json'), $json."\n");
    fwrite(STDOUT, 'PHASE12_MEASUREMENT='.json_encode($evidence, JSON_THROW_ON_ERROR)."\n");
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

it('serializes scheduled report workers with one committed snapshot on PostgreSQL', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $f = new AnalyticsFixture;
    $s = app(ScheduledAnalyticsReports::class);
    $id = $s->create($f->actor, 'counts');
    $f->time = new DateTimeImmutable('2026-10-04T00:00:01Z');
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->toBeArray();
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        try {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            if (fread($pair[1], 1) !== 'S') {
                throw new RuntimeException('Missing parent signal.');
            }
            fwrite($pair[1], $s->run($id) ? '1' : '0');
            fclose($pair[1]);
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
    fclose($pair[1]);
    try {
        DB::beginTransaction();
        DB::table('analytics_report_schedules')->where('id', $id)->lockForUpdate()->first();
        fwrite($pair[0], 'S');
        expect($s->run($id))->toBeTrue();
        DB::commit();
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 1))->toBe('0');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('analytics_report_runs')->count())->toBe(1)
            ->and(DB::table('analytics_report_runs')->value('status'))->toBe('complete')
            ->and(DB::table('analytics_report_runs')->value('attempts'))->toBe(1)
            ->and(DB::table('analytics_snapshots')->count())->toBe(1);
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

it('serializes replayed source reconciliation with one durable checkpoint on PostgreSQL', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $f = new AnalyticsFixture;
    $s = app(AnalyticsQuality::class);
    app(AnalyticsFacts::class)->project($f->actor, $f->event('pg-quality'));
    $input = [$f->actor, 'pg-replay', 'fixture', 'product.viewed', new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now()];
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->toBeArray();
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        try {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            if (fread($pair[1], 1) !== 'S') {
                throw new RuntimeException('Missing parent signal.');
            }
            fwrite($pair[1], $s->reconcile(...$input)['fingerprint']);
            fclose($pair[1]);
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
    fclose($pair[1]);
    try {
        DB::beginTransaction();
        DB::table('workspaces')->where('id', $f->actor->workspaceId)->lockForUpdate()->first();
        fwrite($pair[0], 'S');
        $result = $s->reconcile(...$input);
        DB::commit();
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 64))->toBe($result['fingerprint']);
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('analytics_reconciliations')->count())->toBe(1)
            ->and($s->read($f->actor, $result['id']))->toBe($result);
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
