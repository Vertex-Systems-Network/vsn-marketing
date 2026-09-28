<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\EnrollSubjectInJourney;
use App\Modules\Journeys\Application\ReplayJourneyExecution;
use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\JourneyAttemptPolicy;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

const TASK0051_CAPTURE_ACK = 'I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT';
const TASK0051_CAPTURE_RESULT = 'TASK0051_WORKER_RESULT:';

function task0051CaptureFail(string $message): never
{
    fwrite(STDERR, 'TASK-0051 benchmark capture blocked: '.$message.PHP_EOL);
    exit(2);
}

function task0051CaptureHelp(): string
{
    return <<<'HELP'
TASK-0051 RBT-052 journey execution capture; external authorized benchmark only.

php tools/task0051_benchmark_capture.php \
  --benchmark-id=journey-prodrep-01 --commit-sha=<exact-source-sha> \
  --database=vsn_marketing_benchmark --resource-profile=<reviewed-resource-id> \
  --runner-image-sha=<64-character-image-digest> --cpu-count=4 --memory-mib=8192 \
  --runs=2 --operations=100 --concurrency=4 --seed=51 \
  --output=/absolute/path/journey-evidence.json \
  --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT

Requires APP_ENV=benchmark, exact source attestation, dedicated PostgreSQL and
Redis, forward migrations and explicit journey enrollment cap. Creates only
uniquely named synthetic benchmark rows. No provider action, external network
latency, destructive reset, threshold approval, or automatic deployment run.
HELP;
}

function task0051CaptureInt(mixed $value, string $field, int $default, int $min, int $max): int
{
    if ($value === null || $value === false || $value === '') {
        if ($default < $min || $default > $max) {
            task0051CaptureFail($field.' is required');
        }

        return $default;
    }
    if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $value) || (int) $value < $min || (int) $value > $max) {
        task0051CaptureFail($field.' must be an integer between '.$min.' and '.$max);
    }

    return (int) $value;
}

/** @return array<string, mixed> */
function task0051CaptureOptions(array $options): array
{
    if (($options['ack'] ?? null) !== TASK0051_CAPTURE_ACK || (string) getenv('APP_ENV') !== 'benchmark') {
        task0051CaptureFail('exact acknowledgement and APP_ENV=benchmark are required');
    }
    $id = (string) ($options['benchmark-id'] ?? '');
    $sha = strtolower((string) ($options['commit-sha'] ?? ''));
    $database = (string) ($options['database'] ?? '');
    $resource = (string) ($options['resource-profile'] ?? '');
    $image = strtolower((string) ($options['runner-image-sha'] ?? ''));
    $output = (string) ($options['output'] ?? '');
    if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $id)
        || ! preg_match('/^[0-9a-f]{40}$/D', $sha)
        || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $resource)
        || ! preg_match('/^[0-9a-f]{64}$/D', $image)
        || $database === '' || $output === '' || $output[0] !== '/') {
        task0051CaptureFail('benchmark ID, exact SHA, database, resource profile, image digest, and absolute output are required');
    }
    if (file_exists($output) || is_link($output) || ! is_dir(dirname($output)) || ! is_writable(dirname($output))) {
        task0051CaptureFail('output must be a new file in an existing writable directory');
    }
    $runs = task0051CaptureInt($options['runs'] ?? null, '--runs', 2, 2, 5);
    $operations = task0051CaptureInt($options['operations'] ?? null, '--operations', 100, 20, 1000);
    $concurrency = task0051CaptureInt($options['concurrency'] ?? null, '--concurrency', 4, 2, 16);
    $seed = task0051CaptureInt($options['seed'] ?? null, '--seed', 51, 1, 1000000);
    $cpu = task0051CaptureInt($options['cpu-count'] ?? null, '--cpu-count', 0, 1, 256);
    $memory = task0051CaptureInt($options['memory-mib'] ?? null, '--memory-mib', 0, 512, 1048576);
    if ($concurrency > $operations) {
        task0051CaptureFail('concurrency cannot exceed operations');
    }

    return compact('id', 'sha', 'database', 'resource', 'image', 'cpu', 'memory', 'output', 'runs', 'operations', 'concurrency', 'seed');
}

/** @return array<string, mixed> */
function task0051CapturePreflight(string $sha, string $database): array
{
    $process = new Process([
        PHP_BINARY, __DIR__.'/task0051_benchmark_preflight.php',
        '--commit-sha='.$sha, '--database='.$database, '--ack='.TASK0051_CAPTURE_ACK,
    ], dirname(__DIR__));
    $process->setTimeout(30);
    $process->run();
    if (! $process->isSuccessful()) {
        task0051CaptureFail('read-only preflight rejected source or environment');
    }
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($result) || ($result['preflight'] ?? null) !== 'passed' || ($result['source_sha'] ?? null) !== $sha) {
        task0051CaptureFail('malformed or mismatched preflight result');
    }

    return $result;
}

function task0051CaptureBootstrap(): void
{
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

function task0051CaptureMillis(int $start): float
{
    return round((hrtime(true) - $start) / 1000000, 4);
}

/** @param list<array<string, mixed>> $samples @return array<string, float> */
function task0051CapturePercentiles(array $samples, string $field): array
{
    $values = array_map(static fn (array $sample): float => (float) $sample[$field], $samples);
    sort($values, SORT_NUMERIC);
    $count = count($values);

    return [
        'p50' => $values[(int) ceil(0.50 * $count) - 1],
        'p95' => $values[(int) ceil(0.95 * $count) - 1],
        'p99' => $values[(int) ceil(0.99 * $count) - 1],
    ];
}

/** @return array{schema_version: int, nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>} */
function task0051CaptureGraph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'benchmark.synthetic']],
            ['id' => 'condition', 'type' => 'condition', 'config' => ['field' => 'synthetic.score', 'operator' => 'greater_than', 'value' => 0]],
            ['id' => 'wait', 'type' => 'wait', 'config' => ['seconds' => 1]],
            ['id' => 'action', 'type' => 'action', 'config' => ['capability' => 'benchmark.stub']],
            ['id' => 'finish', 'type' => 'end'],
        ],
        'edges' => [
            ['from' => 'entry', 'to' => 'condition'], ['from' => 'condition', 'to' => 'wait'],
            ['from' => 'wait', 'to' => 'action'], ['from' => 'action', 'to' => 'finish'],
        ],
    ];
}

/** @return array{workspace: string, version: string, organization: string} */
function task0051CaptureWorkspace(string $tag, array $graph, string $hash): array
{
    $organization = Organization::query()->create(['name' => $tag, 'slug' => $tag]);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => $tag, 'slug' => $tag]);
    $workspaceId = (string) $workspace->getKey();
    $journey = (string) Str::uuid();
    $version = (string) Str::uuid();
    $now = now();
    DB::table('journeys')->insert(['id' => $journey, 'workspace_id' => $workspaceId, 'name' => $tag, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_versions')->insert([
        'id' => $version, 'workspace_id' => $workspaceId, 'journey_id' => $journey, 'version_number' => 1,
        'graph' => json_encode($graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'definition_hash' => $hash,
        'reentry_policy' => 'never', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['workspace' => $workspaceId, 'version' => $version, 'organization' => (string) $organization->getKey()];
}

/** @return array{execution: string, enrollment_ms: float, duplicate_ms: float} */
function task0051CaptureExecution(array $scope, int $index): array
{
    $tenant = new TenantContext($scope['organization'], $scope['workspace'], null, 'synthetic-benchmark-actor');
    $subject = (string) Str::uuid();
    $event = 'benchmark-'.$index;
    $enroller = app(EnrollSubjectInJourney::class);
    $start = hrtime(true);
    $enrollment = $enroller->enroll($tenant, $scope['version'], $subject, $event);
    $enrollmentMs = task0051CaptureMillis($start);
    $start = hrtime(true);
    $duplicate = $enroller->enroll($tenant, $scope['version'], $subject, $event);
    $duplicateMs = task0051CaptureMillis($start);
    if ($enrollment['duplicate'] || ! $duplicate['duplicate'] || $duplicate['id'] !== $enrollment['id']) {
        task0051CaptureFail('enrollment idempotency invariant failed');
    }
    $execution = (string) Str::uuid();
    $now = now();
    DB::table('journey_executions')->insert([
        'id' => $execution, 'workspace_id' => $scope['workspace'], 'journey_version_id' => $scope['version'],
        'subject_id' => $subject, 'enrollment_id' => $enrollment['id'], 'execution_key' => hash('sha256', $execution),
        'status' => 'queued', 'revision' => 0, 'transition_history' => '[]', 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['execution' => $execution, 'enrollment_ms' => $enrollmentMs, 'duplicate_ms' => $duplicateMs];
}

/** @return array<string, mixed> */
function task0051CaptureWorker(array $payload): array
{
    if (($payload['ack'] ?? null) !== TASK0051_CAPTURE_ACK || ! isset($payload['sha'], $payload['database'], $payload['items'], $payload['cap'])
        || ! is_array($payload['items']) || $payload['items'] === []) {
        task0051CaptureFail('invalid worker authorization or fixture payload');
    }
    task0051CapturePreflight((string) $payload['sha'], (string) $payload['database']);
    task0051CaptureBootstrap();
    $repository = app(JourneyNodeAttemptRepository::class);
    $policy = new JourneyAttemptPolicy(maxWorkspaceConcurrent: (int) $payload['cap'], leaseSeconds: 60);
    $samples = [];
    foreach ($payload['items'] as $item) {
        if (! is_array($item) || ! isset($item['workspace'], $item['execution'], $item['node'])) {
            task0051CaptureFail('invalid worker operation');
        }
        $fixture = DB::table('journey_executions')
            ->join('journey_versions', 'journey_versions.id', '=', 'journey_executions.journey_version_id')
            ->join('journeys', 'journeys.id', '=', 'journey_versions.journey_id')
            ->join('workspaces', 'workspaces.id', '=', 'journey_executions.workspace_id')
            ->where('journey_executions.workspace_id', $item['workspace'])
            ->where('journey_executions.id', $item['execution'])
            ->where('workspaces.slug', 'like', 'journey-bench-%')
            ->where('journeys.name', 'like', 'journey-bench-%')
            ->exists();
        if (! $fixture || ! in_array($item['node'], ['condition', 'wait', 'action'], true)) {
            task0051CaptureFail('worker operation is outside synthetic journey fixture');
        }
        $now = new DateTimeImmutable;
        $total = hrtime(true);
        $start = hrtime(true);
        $claim = $repository->claim($item['workspace'], $item['execution'], $item['node'], 1, $now, $policy);
        $claimMs = task0051CaptureMillis($start);
        if ($claim === null) {
            task0051CaptureFail('normal operation unexpectedly rejected by workspace attempt cap');
        }
        $start = hrtime(true);
        $duplicate = $repository->claim($item['workspace'], $item['execution'], $item['node'], 1, $now, $policy);
        $duplicateMs = task0051CaptureMillis($start);
        $start = hrtime(true);
        $completed = $repository->complete($item['workspace'], $item['execution'], $claim['attempt_key'], $claim['lease_token'], $now->modify('+1 second'));
        $completeMs = task0051CaptureMillis($start);
        if ($duplicate !== null || ! $completed) {
            task0051CaptureFail('node attempt idempotency or completion invariant failed');
        }
        $samples[] = [
            'index' => $item['index'], 'workspace_slot' => $item['slot'], 'node' => $item['node'],
            'claim_ms' => $claimMs, 'duplicate_ms' => $duplicateMs, 'complete_ms' => $completeMs,
            'end_to_end_ms' => task0051CaptureMillis($total), 'outcome' => 'succeeded',
        ];
    }

    return ['samples' => $samples];
}

/** @return array<string, mixed> */
function task0051CaptureRun(array $options, int $run): array
{
    $graph = task0051CaptureGraph();
    $hash = app(JourneyGraphValidator::class)->hash($graph);
    $tag = 'journey-bench-'.bin2hex(random_bytes(6)).'-'.$run;
    $scopes = [task0051CaptureWorkspace($tag.'-a', $graph, $hash), task0051CaptureWorkspace($tag.'-b', $graph, $hash)];
    $items = [];
    $enrollmentSamples = [];
    for ($i = 0; $i < $options['operations']; $i++) {
        $slot = ($i + $options['seed']) % 2;
        $result = task0051CaptureExecution($scopes[$slot], $i);
        $node = ['condition', 'wait', 'action'][($i + $options['seed']) % 3];
        $items[] = ['index' => $i, 'slot' => $slot, 'workspace' => $scopes[$slot]['workspace'], 'execution' => $result['execution'], 'node' => $node];
        $enrollmentSamples[] = ['index' => $i, 'workspace_slot' => $slot, 'enroll_ms' => $result['enrollment_ms'], 'duplicate_ms' => $result['duplicate_ms']];
    }
    $groups = array_fill(0, $options['concurrency'], []);
    foreach ($items as $i => $item) {
        $groups[$i % $options['concurrency']][] = $item;
    }
    $workers = [];
    $started = hrtime(true);
    foreach ($groups as $group) {
        $payload = base64_encode(json_encode([
            'ack' => TASK0051_CAPTURE_ACK, 'sha' => $options['sha'], 'database' => $options['database'],
            'cap' => $options['concurrency'], 'items' => $group,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $worker = new Process([PHP_BINARY, __FILE__, '--worker'], dirname(__DIR__), ['TASK0051_CAPTURE_WORKER_PAYLOAD' => $payload]);
        $worker->setTimeout(600);
        $worker->start();
        $workers[] = $worker;
    }
    $samples = [];
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            task0051CaptureFail('worker failed; discard the entire run');
        }
        $output = trim($worker->getOutput());
        $position = strrpos($output, TASK0051_CAPTURE_RESULT);
        if ($position === false) {
            task0051CaptureFail('worker result marker missing');
        }
        $result = json_decode(substr($output, $position + strlen(TASK0051_CAPTURE_RESULT)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! is_array($result['samples'] ?? null)) {
            task0051CaptureFail('worker samples malformed');
        }
        array_push($samples, ...$result['samples']);
    }
    $elapsed = task0051CaptureMillis($started);
    usort($samples, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
    if (count($samples) !== $options['operations'] || count(array_unique(array_column($samples, 'index'))) !== count($samples)) {
        task0051CaptureFail('worker sample count or identity mismatch');
    }
    foreach ($items as $item) {
        $attempt = DB::table('journey_node_attempts')->where('workspace_id', $item['workspace'])->where('execution_id', $item['execution'])->first();
        $transitions = DB::table('journey_execution_transitions')->where('workspace_id', $item['workspace'])->where('execution_id', $item['execution'])->orderBy('transition_revision')->pluck('event_type')->all();
        if ($attempt === null || $attempt->status !== 'succeeded' || $transitions !== ['attempt_claimed', 'attempt_succeeded']) {
            task0051CaptureFail('persisted attempt or transition invariant failed');
        }
    }

    return [
        'graph_hash' => $hash, 'node_mix' => array_count_values(array_column($items, 'node')),
        'operations' => count($samples), 'concurrency' => $options['concurrency'],
        'elapsed_ms' => $elapsed, 'throughput_per_second' => round(count($samples) * 1000 / max($elapsed, 0.001), 3),
        'percentile_method' => 'nearest_rank',
        'latency_percentiles_ms' => [
            'enroll' => task0051CapturePercentiles($enrollmentSamples, 'enroll_ms'),
            'claim' => task0051CapturePercentiles($samples, 'claim_ms'),
            'complete' => task0051CapturePercentiles($samples, 'complete_ms'),
            'end_to_end' => task0051CapturePercentiles($samples, 'end_to_end_ms'),
        ],
        'enrollment_samples' => $enrollmentSamples, 'attempt_samples' => $samples,
        'fault_checks' => task0051CaptureFaultChecks($scopes[0]),
    ];
}

/** @return array<string, mixed> */
function task0051CaptureFaultChecks(array $scope): array
{
    $scenarioStarted = hrtime(true);
    $repository = app(JourneyNodeAttemptRepository::class);
    $one = task0051CaptureExecution($scope, random_int(1000000, 2000000))['execution'];
    $two = task0051CaptureExecution($scope, random_int(2000001, 3000000))['execution'];
    $now = new DateTimeImmutable;
    $limited = new JourneyAttemptPolicy(maxWorkspaceConcurrent: 1, leaseSeconds: 10, maxAttempts: 2, retryDelaySeconds: 1);
    $first = $repository->claim($scope['workspace'], $one, 'wait', 1, $now, $limited);
    $saturated = $repository->claim($scope['workspace'], $two, 'wait', 1, $now, $limited) === null;
    if ($first === null) {
        task0051CaptureFail('fault fixture first claim failed');
    }
    $reclaimed = $repository->claim($scope['workspace'], $one, 'wait', 1, $now->modify('+11 seconds'), $limited);
    if ($reclaimed === null) {
        task0051CaptureFail('expired lease did not reclaim');
    }
    $staleFenced = ! $repository->complete($scope['workspace'], $one, $first['attempt_key'], $first['lease_token'], $now->modify('+12 seconds'));
    $retry = $repository->fail($scope['workspace'], $one, $reclaimed['attempt_key'], $reclaimed['lease_token'], ['code' => 'temporary'], true, false, $limited, $now->modify('+12 seconds')) === 'retryable';
    $second = $repository->claim($scope['workspace'], $one, 'wait', 2, $now->modify('+14 seconds'), $limited);
    if ($second === null) {
        task0051CaptureFail('second retry attempt did not claim');
    }
    $deadLetter = $repository->fail($scope['workspace'], $one, $second['attempt_key'], $second['lease_token'], ['code' => 'temporary'], true, false, $limited, $now->modify('+15 seconds')) === 'dead_letter';
    $next = $repository->claim($scope['workspace'], $two, 'wait', 1, $now->modify('+16 seconds'), $limited);
    if ($next === null) {
        task0051CaptureFail('capacity did not release');
    }
    $cancelled = $repository->cancelExecution($scope['workspace'], $two, $now->modify('+17 seconds'));
    $lateFenced = ! $repository->complete($scope['workspace'], $two, $next['attempt_key'], $next['lease_token'], $now->modify('+18 seconds'));
    $unknownExecution = task0051CaptureExecution($scope, random_int(3000001, 4000000))['execution'];
    $unknownClaim = $repository->claim($scope['workspace'], $unknownExecution, 'action', 1, $now->modify('+19 seconds'), $limited);
    if ($unknownClaim === null) {
        task0051CaptureFail('unknown outcome fixture did not claim');
    }
    $unknownStarted = hrtime(true);
    $unknownReview = $repository->fail($scope['workspace'], $unknownExecution, $unknownClaim['attempt_key'], $unknownClaim['lease_token'], ['code' => 'timeout'], true, true, $limited, $now->modify('+20 seconds')) === 'operator_review';
    $unknownMs = task0051CaptureMillis($unknownStarted);

    $actor = User::query()->create(['name' => 'Synthetic Replay Operator', 'email' => 'benchmark-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(16))]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($actor, $scope['workspace']);
    $role = $roles->createRole($scope['workspace'], 'benchmark-replay-'.bin2hex(random_bytes(4)), 'Benchmark Replay');
    $roles->grantPermission($role, PermissionCatalog::JOURNEY_REPLAY);
    $roles->assignRole($membership, $role);
    $tenant = new TenantContext($scope['organization'], $scope['workspace'], null, (string) $actor->getKey());
    $service = app(ReplayJourneyExecution::class);
    $replayStarted = hrtime(true);
    $replay = $service->handle($tenant, $actor, $one, 'benchmark-replay');
    $replayMs = task0051CaptureMillis($replayStarted);
    $duplicateStarted = hrtime(true);
    $duplicate = $service->handle($tenant, $actor, $one, 'benchmark-replay');
    $replayDuplicateMs = task0051CaptureMillis($duplicateStarted);
    $replayPinned = ! $replay['duplicate'] && $duplicate['duplicate'] && $duplicate['id'] === $replay['id']
        && $replay['journey_version_id'] === $scope['version'];
    $foreignScope = new TenantContext($scope['organization'], (string) Str::uuid(), null, (string) $actor->getKey());
    $crossWorkspaceRejected = $repository->claim($foreignScope->workspaceId, $one, 'wait', 1, $now, $limited) === null;
    $checks = compact('saturated', 'staleFenced', 'retry', 'deadLetter', 'cancelled', 'lateFenced', 'unknownReview', 'replayPinned', 'crossWorkspaceRejected');
    if (in_array(false, $checks, true)) {
        task0051CaptureFail('fault/replay invariant failed');
    }
    $checks['measurements_ms'] = [
        'scenario' => task0051CaptureMillis($scenarioStarted),
        'unknown_outcome' => $unknownMs,
        'replay' => $replayMs,
        'replay_duplicate' => $replayDuplicateMs,
    ];

    return $checks;
}

try {
    $options = getopt('', ['help', 'worker', 'benchmark-id:', 'commit-sha:', 'database:', 'resource-profile:', 'runner-image-sha:', 'cpu-count:', 'memory-mib:', 'runs::', 'operations::', 'concurrency::', 'seed::', 'output:', 'ack:']);
    if (isset($options['help'])) {
        echo task0051CaptureHelp().PHP_EOL;
        exit(0);
    }
    if (isset($options['worker'])) {
        $raw = (string) getenv('TASK0051_CAPTURE_WORKER_PAYLOAD');
        if ($raw === '' || strlen($raw) > 1000000) {
            task0051CaptureFail('worker payload missing or oversized');
        }
        $payload = json_decode(base64_decode($raw, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            task0051CaptureFail('worker payload malformed');
        }
        require_once dirname(__DIR__).'/vendor/autoload.php';
        echo TASK0051_CAPTURE_RESULT.json_encode(task0051CaptureWorker($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    $normalized = task0051CaptureOptions($options);
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $preflight = task0051CapturePreflight($normalized['sha'], $normalized['database']);
    task0051CaptureBootstrap();
    if ($preflight['enrollment_cap'] < (int) ceil($normalized['operations'] / 2) + 3) {
        task0051CaptureFail('configured enrollment cap is below the declared fixture demand');
    }
    $warmupOptions = $normalized;
    $warmupOptions['operations'] = 20;
    $warmup = task0051CaptureRun($warmupOptions, -1);
    $runs = [];
    for ($i = 0; $i < $normalized['runs']; $i++) {
        $runs[] = task0051CaptureRun($normalized, $i);
    }
    $evidence = [
        'schema_version' => 1, 'benchmark_id' => $normalized['id'], 'source_sha' => $normalized['sha'],
        'resource_profile' => $normalized['resource'], 'runner_image_sha' => $normalized['image'],
        'cpu_count' => $normalized['cpu'], 'memory_mib' => $normalized['memory'], 'fixture_seed' => $normalized['seed'],
        'database' => $normalized['database'], 'preflight' => $preflight,
        'provider_latency_measured' => false, 'redis_queue_measured' => false,
        'sample_scope' => 'synthetic enrollment and PostgreSQL node-attempt persistence with concurrent workers',
        'warmup' => ['operations' => $warmup['operations'], 'elapsed_ms' => $warmup['elapsed_ms'], 'fault_checks' => $warmup['fault_checks']],
        'runs' => $runs, 'captured_at_utc' => gmdate(DATE_ATOM),
    ];
    $encoded = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    $validation = new Process(['python3', __DIR__.'/task0051_benchmark_evidence.py', '--stdin'], dirname(__DIR__), null, $encoded);
    $validation->setTimeout(30);
    $validation->run();
    if (! $validation->isSuccessful()) {
        task0051CaptureFail('raw evidence structure or invariant validation failed');
    }
    $temporary = tempnam(dirname($normalized['output']), '.task0051-capture-');
    if ($temporary === false) {
        task0051CaptureFail('cannot create evidence staging file');
    }
    try {
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new RuntimeException('staging write failed');
        }
        if (! link($temporary, $normalized['output'])) {
            throw new RuntimeException('output appeared or cannot be exclusively created');
        }
    } finally {
        unlink($temporary);
    }
    echo json_encode(['output' => $normalized['output'], 'sha256' => hash_file('sha256', $normalized['output']), 'runs' => count($runs)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $throwable) {
    task0051CaptureFail('unexpected '.get_debug_type($throwable).' at '.basename($throwable->getFile()).':'.$throwable->getLine());
}
