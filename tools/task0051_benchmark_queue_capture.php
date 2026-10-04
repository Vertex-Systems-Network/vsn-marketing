<?php

use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\ConsentRecord;
use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\JourneyNodeJob;
use App\Modules\Journeys\Application\RedispatchDueJourneyWork;
use App\Modules\Journeys\Application\StartJourneyExecution;
use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

const RBT052_ACK = 'I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT';

function rbt052Fail(string $message): never
{
    fwrite(STDERR, 'RBT-052 queue capture rejected: '.$message.PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function rbt052Options(): array
{
    $raw = getopt('', ['benchmark-id:', 'commit-sha:', 'database:', 'resource-profile:', 'runner-image-sha:', 'cpu-count:', 'memory-mib:', 'runs:', 'operations:', 'concurrency:', 'seed:', 'output:', 'ack:']);
    if (($raw['ack'] ?? null) !== RBT052_ACK || getenv('APP_ENV') !== 'benchmark' || getenv('RBT052_SYNTHETIC_ACTIONS') !== '1') {
        rbt052Fail('dedicated benchmark environment and exact acknowledgement required');
    }
    foreach (['benchmark-id', 'commit-sha', 'database', 'resource-profile', 'runner-image-sha', 'cpu-count', 'memory-mib', 'runs', 'operations', 'concurrency', 'seed', 'output'] as $required) {
        if (! is_string($raw[$required] ?? null) || $raw[$required] === '') {
            rbt052Fail('missing '.$required);
        }
    }
    if (! preg_match('/^[0-9a-f]{40}$/D', $raw['commit-sha']) || ! preg_match('/^[0-9a-f]{64}$/D', $raw['runner-image-sha'])
        || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $raw['benchmark-id'])
        || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $raw['resource-profile'])
        || ! str_starts_with($raw['database'], 'vsn_marketing_benchmark')
        || ! str_starts_with($raw['output'], '/') || file_exists($raw['output'])) {
        rbt052Fail('invalid source, fixture, or output identity');
    }
    foreach (['cpu-count' => [1, 256], 'memory-mib' => [512, 1048576], 'runs' => [2, 5], 'operations' => [20, 1000], 'concurrency' => [2, 16], 'seed' => [1, 1000000]] as $key => [$low, $high]) {
        if (! preg_match('/^[1-9][0-9]*$/D', $raw[$key]) || (int) $raw[$key] < $low || (int) $raw[$key] > $high) {
            rbt052Fail('invalid '.$key);
        }
        $raw[$key] = (int) $raw[$key];
    }

    return $raw;
}

/** @return array<string, mixed> */
function rbt052Preflight(array $options): array
{
    $process = new Process([PHP_BINARY, __DIR__.'/task0051_benchmark_preflight.php',
        '--commit-sha='.$options['commit-sha'], '--database='.$options['database'], '--ack='.RBT052_ACK], dirname(__DIR__));
    $process->setTimeout(30);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    if (($result['preflight'] ?? null) !== 'passed' || ($result['source_sha'] ?? null) !== $options['commit-sha']) {
        rbt052Fail('source/environment preflight mismatch');
    }

    return $result;
}

/** @return array<string, mixed> */
function rbt052Graph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'benchmark.synthetic']],
            ['id' => 'branch', 'type' => 'branch', 'config' => ['field' => 'synthetic.score', 'operator' => 'greater_than', 'value' => 0]],
            ['id' => 'wait', 'type' => 'wait', 'config' => ['seconds' => 1]],
            ['id' => 'action', 'type' => 'action', 'config' => ['capability' => 'benchmark.stub', 'input' => ['channel' => 'email', 'purpose' => 'marketing']]],
            ['id' => 'exit', 'type' => 'exit', 'config' => ['event' => 'benchmark.synthetic']],
            ['id' => 'finish', 'type' => 'end'],
        ],
        'edges' => [
            ['from' => 'entry', 'to' => 'branch'],
            ['from' => 'branch', 'to' => 'wait', 'type' => 'true'],
            ['from' => 'branch', 'to' => 'exit', 'type' => 'false'],
            ['from' => 'wait', 'to' => 'action'],
            ['from' => 'action', 'to' => 'finish'],
        ],
    ];
}

/** @return array{workspace:string,version:string,type:string,contact:string} */
function rbt052Fixture(string $tag, array $graph, string $hash): array
{
    $org = Organization::query()->create(['name' => $tag, 'slug' => $tag]);
    $workspace = Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => $tag, 'slug' => $tag]);
    $workspaceId = (string) $workspace->getKey();
    $version = (string) Str::uuid();
    $journey = (string) Str::uuid();
    $type = (string) Str::uuid();
    $now = now();
    DB::table('journeys')->insert(['id' => $journey, 'workspace_id' => $workspaceId, 'name' => $tag, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_versions')->insert([
        'id' => $version, 'workspace_id' => $workspaceId, 'journey_id' => $journey,
        'version_number' => 1, 'graph' => json_encode(app(JourneyGraphValidator::class)->normalize($graph), JSON_THROW_ON_ERROR),
        'definition_hash' => $hash, 'reentry_policy' => 'never', 'status' => 'published',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('event_types')->insert(['id' => $type, 'workspace_id' => $workspaceId, 'canonical_name' => 'benchmark.synthetic', 'schema_version' => 1, 'created_at' => $now]);

    return ['workspace' => $workspaceId, 'version' => $version, 'type' => $type, 'contact' => ''];
}

/** @return array{execution:string,workspace:string,index:int,slot:int} */
function rbt052Enroll(array $fixture, int $index, int $slot, string $policyCase = 'allowed'): array
{
    $contact = (string) Str::uuid();
    $event = (string) Str::uuid();
    $enrollment = (string) Str::uuid();
    $now = now();
    DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $fixture['workspace'], 'created_at' => $now, 'updated_at' => $now]);
    if ($policyCase !== 'missing_consent') {
        app(ConsentRecordRepository::class)->append(new ConsentRecord(
            (string) Str::uuid(), $fixture['workspace'], $contact, 'email', 'marketing',
            'benchmark-fixture', ConsentDecision::Granted, new DateTimeImmutable('now'),
        ));
    }
    if ($policyCase === 'suppressed') {
        $at = new DateTimeImmutable('now');
        app(DatabaseSuppressionRepository::class)->appendSuppression(new SuppressionRecord(
            (string) Str::uuid(), $fixture['workspace'], $contact, 'email', 'marketing',
            SuppressionAuthorityType::Unsubscribe, 'benchmark-fixture', null, null,
            hash('sha256', $contact), $at, $at, null, ['fixture' => 'synthetic'],
        ));
    }
    DB::table('customer_events')->insert([
        'id' => $event, 'workspace_id' => $fixture['workspace'], 'event_type_id' => $fixture['type'],
        'contact_id' => $contact, 'occurred_at' => $now, 'received_at' => $now, 'source' => 'benchmark',
        'schema_version' => 1, 'subjects' => '{}', 'payload' => '{"synthetic.score":1}',
        'source_metadata' => '{}', 'created_at' => $now,
    ]);
    DB::table('journey_enrollments')->insert([
        'id' => $enrollment, 'workspace_id' => $fixture['workspace'], 'journey_version_id' => $fixture['version'],
        'subject_id' => $contact, 'trigger_event_id' => $event, 'enrollment_key' => hash('sha256', $enrollment),
        'generation' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $execution = app(StartJourneyExecution::class)->handle($fixture['workspace'], $enrollment);

    return ['execution' => $execution, 'workspace' => $fixture['workspace'], 'index' => $index, 'slot' => $slot];
}

/** @param list<array<string, mixed>> $items */
function rbt052Workers(int $concurrency, array $items): void
{
    $workers = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=journeys', '--stop-when-empty', '--sleep=1', '--tries=3', '--timeout=60'], dirname(__DIR__));
        $worker->setTimeout(120);
        $worker->start();
        $workers[] = $worker;
    }
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            rbt052Fail('Redis journey worker failed');
        }
    }
}

/** @param list<array<string, mixed>> $items */
function rbt052Progress(array $items, int $passes): string
{
    $ids = array_column($items, 'execution');
    $executions = DB::table('journey_executions')->whereIn('id', $ids)
        ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
    $work = DB::table('journey_work_items')->whereIn('execution_id', $ids)
        ->select('node_id', 'status', DB::raw('count(*) as total'))->groupBy('node_id', 'status')->get()
        ->mapWithKeys(static fn ($row) => [$row->node_id.':'.$row->status => (int) $row->total])->all();
    $attempts = DB::table('journey_node_attempts')->whereIn('execution_id', $ids)
        ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
    $due = DB::table('journey_work_items')->whereIn('execution_id', $ids)
        ->whereIn('status', ['pending', 'running', 'waiting'])->where('available_at', '<=', now())->count();
    $failure = DB::table('failed_jobs')->orderBy('id')->value('exception');
    $failureType = is_string($failure) ? strtok($failure, "\r\n") : null;
    $failureType = $failureType === false ? null : substr($failureType, 0, 240);

    return json_encode(['passes' => $passes, 'executions' => $executions, 'work' => $work,
        'attempts' => $attempts, 'due_work' => $due, 'failed_jobs' => DB::table('failed_jobs')->count(),
        'first_failure' => $failureType], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function rbt052Run(array $options, int $run): array
{
    $graph = rbt052Graph();
    $hash = app(JourneyGraphValidator::class)->hash($graph);
    $tag = 'journey-bench-'.bin2hex(random_bytes(6)).'-'.$run;
    $fixtures = [rbt052Fixture($tag.'-a', $graph, $hash), rbt052Fixture($tag.'-b', $graph, $hash)];
    $items = [];
    $started = hrtime(true);
    for ($i = 0; $i < $options['operations']; $i++) {
        $slot = ($i + $options['seed']) % 2;
        $items[] = rbt052Enroll($fixtures[$slot], $i, $slot);
    }
    $initialQueueDepth = Queue::connection('redis')->size('journeys');
    if ($initialQueueDepth < count($items)) {
        rbt052Fail('Redis backlog smaller than queued fixture count');
    }
    $passes = 0;
    do {
        rbt052Workers($options['concurrency'], $items);
        $terminal = DB::table('journey_executions')->whereIn('id', array_column($items, 'execution'))->where('status', 'succeeded')->count();
        if ($terminal === count($items)) {
            break;
        }
        if (++$passes > 90 || DB::table('journey_executions')->whereIn('id', array_column($items, 'execution'))
            ->whereIn('status', ['failed', 'blocked', 'cancelled', 'exited'])->exists()) {
            rbt052Fail('journey graph did not complete cleanly: '.rbt052Progress($items, $passes));
        }
        sleep(1); // Harness scheduler tick; journey workers themselves never sleep on waits.
        foreach ($fixtures as $fixture) {
            app(RedispatchDueJourneyWork::class)->handle($fixture['workspace'], 1000);
        }
    } while (true);

    $elapsed = round((hrtime(true) - $started) / 1000000, 4);
    $samples = [];
    foreach ($items as $item) {
        $execution = DB::table('journey_executions')->where('workspace_id', $item['workspace'])->where('id', $item['execution'])->first();
        $nodes = DB::table('journey_work_items')->where('workspace_id', $item['workspace'])->where('execution_id', $item['execution'])->get();
        $types = $nodes->pluck('node_id')->sort()->values()->all();
        if ($execution === null || $execution->status !== 'succeeded' || $types !== ['action', 'branch', 'entry', 'finish', 'wait']
            || $nodes->where('status', 'completed')->count() !== 5 || $nodes->whereNull('queue_age_us')->count() !== 0) {
            rbt052Fail('persisted graph, queue, or action path invariant failed');
        }
        $start = new DateTimeImmutable((string) $execution->created_at);
        $end = new DateTimeImmutable((string) $execution->updated_at);
        $samples[] = [
            'index' => $item['index'], 'workspace_slot' => $item['slot'],
            'queue_age_ms' => round($nodes->max('queue_age_us') / 1000, 4),
            'end_to_end_ms' => round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000, 4),
            'node_attempts' => DB::table('journey_node_attempts')->where('workspace_id', $item['workspace'])->where('execution_id', $item['execution'])->where('status', 'succeeded')->count(),
        ];
        if ($samples[array_key_last($samples)]['node_attempts'] !== 5) {
            rbt052Fail('node attempt count disagrees with complete graph');
        }
    }
    usort($samples, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

    return ['graph_hash' => $hash, 'operations' => count($samples), 'concurrency' => $options['concurrency'],
        'elapsed_ms' => $elapsed, 'throughput_per_second' => round(count($samples) * 1000 / max($elapsed, 0.001), 3),
        'queue_samples' => $samples, 'worker_passes' => $passes + 1,
        'initial_queue_depth' => $initialQueueDepth];
}

/** @return array<string, string> */
function rbt052PolicyProbe(array $options): array
{
    $graph = rbt052Graph();
    $fixture = rbt052Fixture('journey-bench-policy-'.bin2hex(random_bytes(6)), $graph,
        app(JourneyGraphValidator::class)->hash($graph));
    $items = [
        'missing_consent' => rbt052Enroll($fixture, 0, 0, 'missing_consent'),
        'suppressed' => rbt052Enroll($fixture, 1, 0, 'suppressed'),
    ];
    for ($pass = 0; $pass < 10; $pass++) {
        rbt052Workers($options['concurrency'], array_values($items));
        $complete = true;
        foreach ($items as $item) {
            if (DB::table('journey_executions')->where('id', $item['execution'])->value('status') !== 'failed') {
                $complete = false;
            }
        }
        if ($complete) {
            break;
        }
        sleep(1);
        app(RedispatchDueJourneyWork::class)->handle($fixture['workspace'], 10);
    }
    $result = [];
    foreach ($items as $case => $item) {
        $attempt = DB::table('journey_node_attempts')->where('execution_id', $item['execution'])
            ->where('node_id', 'action')->first();
        $work = DB::table('journey_work_items')->where('execution_id', $item['execution'])->where('node_id', 'action')->first();
        if (DB::table('journey_executions')->where('id', $item['execution'])->value('status') !== 'failed'
            || $attempt === null || $attempt->status !== 'dead_letter'
            || $work === null || $work->status !== 'blocked'
            || DB::table('journey_work_items')->where('execution_id', $item['execution'])->where('node_id', 'finish')->exists()) {
            rbt052Fail('policy denial probe did not fail closed: '.$case);
        }
        $result[$case] = 'failed_before_action_dispatch';
    }

    return $result;
}

/** @return array<string, bool> */
function rbt052FaultProbe(array $options): array
{
    $graph = rbt052Graph();
    $hash = app(JourneyGraphValidator::class)->hash($graph);
    $fixture = rbt052Fixture('journey-bench-fault-'.bin2hex(random_bytes(6)), $graph, $hash);
    $other = rbt052Fixture('journey-bench-other-'.bin2hex(random_bytes(6)), $graph, $hash);
    $active = rbt052Enroll($fixture, 0, 0);
    $cancelled = rbt052Enroll($fixture, 1, 0);
    $activeEntry = DB::table('journey_work_items')->where('execution_id', $active['execution'])->where('node_id', 'entry')->value('id');
    $cancelledEntry = DB::table('journey_work_items')->where('execution_id', $cancelled['execution'])->where('node_id', 'entry')->value('id');
    JourneyNodeJob::dispatch($fixture['workspace'], (string) $activeEntry);
    JourneyNodeJob::dispatch($fixture['workspace'], (string) $activeEntry);
    JourneyNodeJob::dispatch($other['workspace'], (string) $activeEntry);
    if (! app(JourneyNodeAttemptRepository::class)->cancelExecution(
        $fixture['workspace'], $cancelled['execution'], new DateTimeImmutable('now'),
    )) {
        rbt052Fail('fault probe cancellation failed');
    }
    JourneyNodeJob::dispatch($fixture['workspace'], (string) $cancelledEntry);
    for ($pass = 0; $pass < 10; $pass++) {
        rbt052Workers($options['concurrency'], [$active, $cancelled]);
        if (DB::table('journey_executions')->where('id', $active['execution'])->value('status') === 'succeeded') {
            break;
        }
        sleep(1);
        app(RedispatchDueJourneyWork::class)->handle($fixture['workspace'], 10);
    }
    $activeNodes = DB::table('journey_work_items')->where('execution_id', $active['execution'])->get();
    $cancelledNodes = DB::table('journey_work_items')->where('execution_id', $cancelled['execution'])->get();
    if (DB::table('journey_executions')->where('id', $active['execution'])->value('status') !== 'succeeded'
        || $activeNodes->where('status', 'completed')->count() !== 5
        || DB::table('journey_node_attempts')->where('execution_id', $active['execution'])->count() !== 5
        || DB::table('journey_executions')->where('id', $cancelled['execution'])->value('status') !== 'cancelled'
        || $cancelledNodes->count() !== 1 || $cancelledNodes->first()->status !== 'cancelled'
        || DB::table('journey_node_attempts')->where('execution_id', $cancelled['execution'])->exists()) {
        rbt052Fail('duplicate, cross-workspace, or cancellation fault invariant failed');
    }

    return ['duplicate_redis_wakeup_idempotent' => true,
        'foreign_workspace_wakeup_rejected' => true, 'cancelled_execution_ignored_stale_wakeup' => true];
}

try {
    $options = rbt052Options();
    $preflight = rbt052Preflight($options);
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    foreach (['journey_work_items', 'journey_waits'] as $requiredTable) {
        if (! Schema::hasTable($requiredTable)) {
            rbt052Fail('durable queue/wait migration missing');
        }
    }
    $warmup = $options;
    $warmup['operations'] = 20;
    $warmupRun = rbt052Run($warmup, -1);
    $runs = [];
    for ($i = 0; $i < $options['runs']; $i++) {
        $runs[] = rbt052Run($options, $i);
    }
    $controlOptions = $options;
    $controlOptions['operations'] = 200;
    $control = rbt052Run($controlOptions, 99);
    $stressOptions = $options;
    $stressOptions['operations'] = 200;
    $stressOptions['concurrency'] = 8;
    $stress = rbt052Run($stressOptions, 100);
    if (DB::table('failed_jobs')->exists()) {
        rbt052Fail('normal graph runs created failed Redis jobs');
    }
    $policyProbe = rbt052PolicyProbe($options);
    $faultProbe = rbt052FaultProbe($options);
    $evidence = [
        'schema_version' => 6, 'benchmark_id' => $options['benchmark-id'], 'source_sha' => $options['commit-sha'],
        'resource_profile' => $options['resource-profile'], 'runner_image_sha' => $options['runner-image-sha'],
        'cpu_count' => $options['cpu-count'], 'memory_mib' => $options['memory-mib'], 'database' => $options['database'],
        'fixture_seed' => $options['seed'], 'preflight' => $preflight,
        'redis_queue_measured' => true, 'graph_traversal_measured' => true,
        'synthetic_action_gate_invoked' => true, 'production_action_policy_measured' => false, 'provider_latency_measured' => false,
        'canonical_consent_suppression_measured' => true, 'policy_denial_probe' => $policyProbe,
        'fault_probe' => $faultProbe,
        'sample_scope' => 'isolated synthetic Redis queue, PostgreSQL pinned graph, bounded wait and no-op action; canonical consent/suppression checked, full production action policy/provider timing excluded',
        'warmup' => $warmupRun, 'runs' => $runs, 'backlog_control' => $control, 'backlog_stress' => $stress,
        'captured_at_utc' => gmdate(DATE_ATOM),
    ];
    $encoded = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    $validator = new Process(['python3', __DIR__.'/task0051_benchmark_queue_evidence.py', '--stdin'], dirname(__DIR__), null, $encoded);
    $validator->mustRun();
    $staging = tempnam(dirname($options['output']), '.rbt052-queue-');
    if ($staging === false || file_put_contents($staging, $encoded, LOCK_EX) !== strlen($encoded) || ! link($staging, $options['output'])) {
        rbt052Fail('exclusive evidence write failed');
    }
    unlink($staging);
    echo json_encode(['output' => $options['output'], 'sha256' => hash_file('sha256', $options['output'])], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    rbt052Fail('capture failed at '.basename($error->getFile()).':'.$error->getLine().' '.get_debug_type($error));
}
