<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Application\ResolveDeliveryReconciliation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

const TASK0024_BENCHMARK_ACK = 'I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT';
const TASK0024_WORKER_PAYLOAD_ENV = 'TASK0024_BENCHMARK_WORKER_PAYLOAD';

/** @return never */
function task0024Fail(string $message, int $exitCode = 2): void
{
    fwrite(STDERR, 'TASK-0024 benchmark capture blocked: '.$message.PHP_EOL);
    exit($exitCode);
}

function task0024Help(): string
{
    return <<<'HELP'
TASK-0024 production-representative delivery benchmark capture

This tool measures the already-implemented delivery engine on a dedicated,
non-production benchmark environment. It emits raw observations only. It does
not infer thresholds, does not grant Delivery-owner approval, and does not
measure external provider/network latency.

Safety requirements:
  - APP_ENV must be exported as exactly: benchmark
  - PostgreSQL must be the active database driver.
  - --database must exactly match the active database name.
  - The database name must visibly identify a benchmark/perf/load/staging/test DB.
  - --ack must exactly equal:
      I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT
  - The checkout HEAD must exactly match --commit-sha.
  - No destructive database reset or Redis-wide flush is performed.

Usage:
  php tools/task0024_benchmark_capture.php \
    --scenario=delivery \
    --benchmark-id=task0024-delivery-prodrep-01 \
    --commit-sha=<40-char-sha> \
    --database=vsn_marketing_benchmark \
    --runs=2 \
    --operations=200 \
    --concurrency=8 \
    --seed=24 \
    --warmup-seconds=5 \
    --measurement-window-seconds=30 \
    --output=/path/to/delivery-evidence.json \
    --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT

Scenarios:
  delivery        queue_age_ms + end_to_end_ms; terminal accepted outcome is
                  recorded through the canonical recovery application service.
  reconciliation  reconciliation_lag_ms; ambiguity is placed into durable
                  reconciliation then resolved through evidence-backed acceptance.

The resulting JSON is validated with tools/delivery_benchmark_evidence.py before
it is atomically moved to --output.
HELP;
}

/** @return array<string, mixed> */
function task0024Options(): array
{
    $options = getopt('', [
        'help',
        'scenario:',
        'benchmark-id:',
        'commit-sha:',
        'database:',
        'runs::',
        'operations::',
        'concurrency::',
        'seed::',
        'warmup-seconds::',
        'measurement-window-seconds::',
        'output:',
        'ack:',
        'overwrite',
    ]);

    return is_array($options) ? $options : [];
}

function task0024PositiveInt(mixed $value, string $name, int $default): int
{
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (! is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== (string) $value) {
        task0024Fail($name.' must be a positive integer');
    }

    return (int) $value;
}

function task0024NonNegativeFloat(mixed $value, string $name, float $default): float
{
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
        task0024Fail($name.' must be a non-negative finite number');
    }

    return (float) $value;
}

function task0024PositiveFloat(mixed $value, string $name, float $default): float
{
    $number = task0024NonNegativeFloat($value, $name, $default);
    if ($number <= 0) {
        task0024Fail($name.' must be greater than zero');
    }

    return $number;
}

/** @return array<string, mixed> */
function task0024NormalizeParentOptions(array $options): array
{
    if (($options['ack'] ?? null) !== TASK0024_BENCHMARK_ACK) {
        task0024Fail('the exact --ack acknowledgement is required before application bootstrap');
    }

    if ((string) getenv('APP_ENV') !== 'benchmark') {
        task0024Fail('APP_ENV must be exported as exactly benchmark');
    }

    $scenario = (string) ($options['scenario'] ?? '');
    if (! in_array($scenario, ['delivery', 'reconciliation'], true)) {
        task0024Fail('--scenario must be delivery or reconciliation');
    }

    $benchmarkId = trim((string) ($options['benchmark-id'] ?? ''));
    if ($benchmarkId === '' || preg_match('/^[A-Za-z0-9._-]+$/', $benchmarkId) !== 1) {
        task0024Fail('--benchmark-id must contain only letters, digits, dot, underscore or dash');
    }

    $commitSha = strtolower(trim((string) ($options['commit-sha'] ?? '')));
    if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
        task0024Fail('--commit-sha must be a 40-character hexadecimal Git SHA');
    }

    $database = trim((string) ($options['database'] ?? ''));
    if ($database === '') {
        task0024Fail('--database is required');
    }

    $output = trim((string) ($options['output'] ?? ''));
    if ($output === '') {
        task0024Fail('--output is required');
    }

    $runs = task0024PositiveInt($options['runs'] ?? null, '--runs', 2);
    if ($runs < 2) {
        task0024Fail('--runs must be at least 2');
    }

    $operations = task0024PositiveInt($options['operations'] ?? null, '--operations', 100);
    $concurrency = task0024PositiveInt($options['concurrency'] ?? null, '--concurrency', 4);
    $seed = task0024PositiveInt($options['seed'] ?? null, '--seed', 24);
    if ($concurrency > $operations) {
        task0024Fail('--concurrency cannot exceed --operations');
    }

    $warmupSeconds = task0024NonNegativeFloat($options['warmup-seconds'] ?? null, '--warmup-seconds', 3.0);
    $measurementWindowSeconds = task0024PositiveFloat(
        $options['measurement-window-seconds'] ?? null,
        '--measurement-window-seconds',
        30.0,
    );

    return [
        'scenario' => $scenario,
        'benchmark_id' => $benchmarkId,
        'commit_sha' => $commitSha,
        'database' => $database,
        'runs' => $runs,
        'operations' => $operations,
        'concurrency' => $concurrency,
        'seed' => $seed,
        'warmup_seconds' => $warmupSeconds,
        'measurement_window_seconds' => $measurementWindowSeconds,
        'output' => $output,
        'overwrite' => array_key_exists('overwrite', $options),
        'ack' => TASK0024_BENCHMARK_ACK,
    ];
}

/** @return Application */
function task0024Bootstrap(string $expectedDatabase)
{
    $root = dirname(__DIR__);
    $autoload = $root.'/vendor/autoload.php';
    $bootstrap = $root.'/bootstrap/app.php';

    if (! is_file($autoload) || ! is_file($bootstrap)) {
        task0024Fail('run from a complete repository checkout with installed Composer dependencies');
    }

    require_once $autoload;
    $app = require $bootstrap;
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('benchmark')) {
        task0024Fail('booted Laravel environment must be benchmark');
    }

    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        task0024Fail('active database driver must be pgsql');
    }

    $actualDatabase = (string) $connection->getDatabaseName();
    if ($actualDatabase !== $expectedDatabase) {
        task0024Fail('--database does not match the active PostgreSQL database name');
    }

    if (preg_match('/(bench|perf|load|staging|test)/i', $actualDatabase) !== 1) {
        task0024Fail('database name must visibly identify a benchmark/perf/load/staging/test database');
    }

    try {
        $pong = app(RedisManager::class)->connection('locks')->command('ping');
    } catch (Throwable $throwable) {
        task0024Fail('Redis locks connection is unavailable: '.$throwable->getMessage());
    }

    if ($pong === false || $pong === null) {
        task0024Fail('Redis locks connection did not answer PING');
    }

    return $app;
}

function task0024VerifyCheckout(string $expectedSha): void
{
    $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(__DIR__));
    $process->setTimeout(10);
    $process->run();

    if (! $process->isSuccessful()) {
        task0024Fail('unable to resolve checkout HEAD: '.trim($process->getErrorOutput()));
    }

    if (strtolower(trim($process->getOutput())) !== $expectedSha) {
        task0024Fail('checkout HEAD does not match --commit-sha');
    }
}

/** @return array{organization_id:string,workspace_id:string,brand_id:string} */
function task0024CreateFixture(string $benchmarkId, int $quotaRemaining): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $suffix = substr(hash('sha256', $benchmarkId.'-'.$workspaceId), 0, 16);
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0024 Benchmark '.$suffix,
        'slug' => 'task0024-benchmark-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0024 Benchmark Workspace '.$suffix,
        'slug' => 'task0024-benchmark-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0024 Benchmark Brand '.$suffix,
        'slug' => 'task0024-benchmark-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $workspaceId,
        'provider_key' => 'task0024-benchmark-'.$suffix,
        'display_name' => 'TASK-0024 Benchmark Provider '.$suffix,
        'category' => 'delivery',
        'metadata' => '{}',
        'source_url' => 'https://benchmark.invalid/provider/'.$suffix,
        'source_version' => 'task0024-benchmark',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHours(12),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_connections')->insert([
        'id' => $connectionId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'name' => 'TASK-0024 Benchmark Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://task0024-benchmark/'.$suffix,
        'requested_scopes' => '[]',
        'granted_scopes' => '[]',
        'roles' => '[]',
        'access_tier' => null,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'provider_review_status' => null,
        'token_expires_at' => null,
        'refresh_supported' => false,
        'last_rotated_at' => null,
        'metadata' => '{}',
        'source_url' => 'https://benchmark.invalid/connection/'.$suffix,
        'source_version' => 'task0024-benchmark',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHours(12),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_capabilities')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'email.send',
        'support_status' => 'supported',
        'required_scopes' => '[]',
        'required_roles' => '[]',
        'constraints' => '{}',
        'source_url' => 'https://benchmark.invalid/capability/'.$suffix,
        'source_version' => 'task0024-benchmark',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHours(12),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_quotas')->insert([
        'id' => $quotaId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'email.send',
        'scope_type' => 'account',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'fixed',
        'window_seconds' => 43200,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => null,
        'used_value' => null,
        'remaining_value' => (string) $quotaRemaining,
        'resets_at' => $now->copy()->addHours(12),
        'dynamically_discovered' => true,
        'discovery_key' => 'task0024-benchmark-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://benchmark.invalid/quota/'.$suffix,
        'source_version' => 'task0024-benchmark',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHours(12),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
    ];
}

/** @return array<string, mixed> */
function task0024Environment(): array
{
    $redisRaw = (string) app(RedisManager::class)->connection('locks')->command('rawCommand', ['INFO', 'server']);
    preg_match('/^redis_version:([^\r\n]+)/m', $redisRaw, $redisMatch);
    $postgres = DB::selectOne('show server_version');
    $cpuInfo = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
    $memInfo = is_readable('/proc/meminfo') ? (string) file_get_contents('/proc/meminfo') : '';
    preg_match_all('/^processor\s*:/m', $cpuInfo, $processorMatches);
    preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memInfo, $memoryMatch);

    $cpuCount = count($processorMatches[0] ?? []);
    $memoryKb = isset($memoryMatch[1]) ? (int) $memoryMatch[1] : 0;
    if ($cpuCount <= 0 || $memoryKb <= 0) {
        task0024Fail('Linux CPU/memory resources could not be measured from /proc');
    }

    return [
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'postgresql_version' => (string) ($postgres->server_version ?? ''),
        'redis_version' => trim((string) ($redisMatch[1] ?? '')),
        'runner' => [
            'os' => php_uname('s').' '.php_uname('r'),
            'cpu_count' => $cpuCount,
            'memory_mb' => max(1, intdiv($memoryKb, 1024)),
            'architecture' => php_uname('m'),
        ],
    ];
}

/** @return list<list<int>> */
function task0024ShardIndices(int $operations, int $concurrency, int $seed): array
{
    $shards = array_fill(0, $concurrency, []);
    for ($index = 0; $index < $operations; $index++) {
        $shards[($index + $seed) % $concurrency][] = $index;
    }

    return $shards;
}

/** @return array{completed:int,elapsed_seconds?:float,observations:array<string,list<float>>} */
function task0024RunPhase(array $options, array $fixture, string $phaseId, float $durationSeconds, int $operations, bool $collect): array
{
    if ($durationSeconds <= 0) {
        return ['completed' => 0, 'observations' => []];
    }

    $redis = app(RedisManager::class)->connection('locks');
    $barrierPrefix = 'task0024:benchmark:'.substr(hash('sha256', $options['benchmark_id'].'-'.$options['seed'].'-'.$phaseId), 0, 24);
    $readyKey = $barrierPrefix.':ready';
    $goKey = $barrierPrefix.':go';
    $redis->command('del', [$readyKey, $goKey]);

    $shards = task0024ShardIndices($operations, $options['concurrency'], $options['seed']);
    $processes = [];

    try {
        foreach ($shards as $workerIndex => $indices) {
            $payload = [
                'ack' => TASK0024_BENCHMARK_ACK,
                'database' => $options['database'],
                'scenario' => $options['scenario'],
                'benchmark_id' => $options['benchmark_id'],
                'phase_id' => $phaseId,
                'worker_index' => $workerIndex,
                'indices' => $indices,
                'concurrency' => $options['concurrency'],
                'seed' => $options['seed'],
                'duration_seconds' => $durationSeconds,
                'collect' => $collect,
                'fixture' => $fixture,
                'ready_key' => $readyKey,
                'go_key' => $goKey,
            ];
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $process = new Process(
                [PHP_BINARY, __FILE__],
                dirname(__DIR__),
                [TASK0024_WORKER_PAYLOAD_ENV => $encoded],
            );
            $process->setTimeout($durationSeconds + 90);
            $process->start();
            $processes[] = $process;
        }

        $readyDeadline = microtime(true) + 30.0;
        while ((int) ($redis->get($readyKey) ?: 0) < $options['concurrency']) {
            foreach ($processes as $process) {
                if ($process->isTerminated() && ! $process->isSuccessful()) {
                    task0024Fail('benchmark worker failed before barrier: '.trim($process->getErrorOutput().' '.$process->getOutput()));
                }
            }
            if (microtime(true) >= $readyDeadline) {
                task0024Fail('benchmark workers did not reach the start barrier within 30 seconds');
            }
            usleep(10_000);
        }

        $startedAt = microtime(true);
        $redis->set($goKey, sprintf('%.6F', $startedAt));

        $completed = 0;
        $observations = [];
        foreach ($processes as $process) {
            $exitCode = $process->wait();
            if ($exitCode !== 0) {
                task0024Fail('benchmark worker failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
            }
            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                task0024Fail('benchmark worker returned malformed JSON');
            }
            $completed += (int) ($decoded['completed'] ?? 0);
            foreach (($decoded['observations'] ?? []) as $metric => $values) {
                if (! is_array($values)) {
                    task0024Fail('benchmark worker returned malformed observation values');
                }
                foreach ($values as $value) {
                    $observations[(string) $metric][] = (float) $value;
                }
            }
        }

        $elapsed = max(0.000001, microtime(true) - $startedAt);

        return [
            'completed' => $completed,
            'elapsed_seconds' => $elapsed,
            'observations' => $observations,
        ];
    } finally {
        $redis->command('del', [$readyKey, $goKey]);
    }
}

/** @return array{completed:int,observations:array<string,list<float>>} */
function task0024WorkerOperation(array $payload, int $index, float $deadline): array
{
    if (microtime(true) >= $deadline) {
        return ['completed' => 0, 'observations' => []];
    }

    $fixture = $payload['fixture'];
    $suffix = substr(hash('sha256', $payload['benchmark_id'].'-'.$payload['seed'].'-'.$payload['phase_id'].'-'.$payload['worker_index'].'-'.$index), 0, 24);
    $context = new TenantContext(
        organizationId: (string) $fixture['organization_id'],
        workspaceId: (string) $fixture['workspace_id'],
        brandId: (string) $fixture['brand_id'],
        actorId: 'task0024-benchmark-'.$payload['worker_index'],
    );

    $contact = app(CreateContact::class)->handle($context, firstName: 'TASK-0024 Benchmark '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $context,
        $contact->id,
        ContactIdentityType::Email,
        'task0024-'.$suffix.'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $context,
        'task0024-benchmark-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'TASK-0024 benchmark '.$suffix],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $context,
        $message->id,
        $contact->id,
        $identity->id,
    );

    $enqueueStartedAt = microtime(true);
    $operation = app(EnqueueDeliveryOperation::class)->handle(
        $context,
        $snapshots->message->id,
        $snapshots->recipient->id,
    );
    $queuedAt = microtime(true);

    $admission = app(AdmitDeliveryOperation::class)->handle($context, $operation);
    while (! $admission->admitted) {
        if ($admission->backpressureReason !== 'concurrency_capacity_exhausted') {
            throw new RuntimeException('benchmark admission blocked: '.($admission->backpressureReason ?? 'unknown'));
        }
        if (microtime(true) >= $deadline) {
            return ['completed' => 0, 'observations' => []];
        }
        usleep(1_000);
        $admission = app(AdmitDeliveryOperation::class)->handle($context, $admission->operation);
    }
    $admittedAt = microtime(true);

    $observations = [];
    if ($payload['scenario'] === 'delivery') {
        app(RecoverDeliveryOperation::class)->handle(
            $context,
            $admission->operation->id,
            (string) Str::uuid(),
            new DeliveryFailureObservation(providerAccepted: true),
        );
        $terminalAt = microtime(true);
        if ($payload['collect']) {
            $observations['queue_age_ms'] = [max(0.0, ($admittedAt - $queuedAt) * 1000)];
            $observations['end_to_end_ms'] = [max(0.0, ($terminalAt - $enqueueStartedAt) * 1000)];
        }
    } else {
        $attemptId = (string) Str::uuid();
        app(RecoverDeliveryOperation::class)->handle(
            $context,
            $admission->operation->id,
            $attemptId,
            new DeliveryFailureObservation(
                errorCategory: ProviderErrorCategory::Unknown,
                requestMayHaveReachedProvider: true,
            ),
            operationExpired: true,
        );
        $heldAt = microtime(true);
        app(ResolveDeliveryReconciliation::class)->handle(
            $context,
            $admission->operation->id,
            $attemptId,
            new DeliveryReconciliationEvidence(
                providerAccepted: true,
                probeAttemptNumber: 1,
                reason: 'task0024_benchmark_acceptance_confirmed',
            ),
        );
        $resolvedAt = microtime(true);
        if ($payload['collect']) {
            $observations['reconciliation_lag_ms'] = [max(0.0, ($resolvedAt - $heldAt) * 1000)];
        }
    }

    return ['completed' => 1, 'observations' => $observations];
}

/** @return never */
function task0024WorkerMain(string $encodedPayload): void
{
    if ((string) getenv('APP_ENV') !== 'benchmark') {
        task0024Fail('worker APP_ENV must be benchmark');
    }

    $decoded = base64_decode($encodedPayload, true);
    if ($decoded === false) {
        task0024Fail('worker payload is not valid base64');
    }
    $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($payload) || ($payload['ack'] ?? null) !== TASK0024_BENCHMARK_ACK) {
        task0024Fail('worker payload acknowledgement is invalid');
    }

    task0024Bootstrap((string) ($payload['database'] ?? ''));
    config([
        'delivery.admission.concurrency_enabled' => true,
        'delivery.admission.global_concurrency_limit' => (int) $payload['concurrency'],
        'delivery.admission.workspace_concurrency_limit' => (int) $payload['concurrency'],
        'delivery.admission.reservation_ttl_seconds' => max(30, (int) ceil((float) $payload['duration_seconds']) + 30),
    ]);

    $redis = app(RedisManager::class)->connection('locks');
    $redis->incr((string) $payload['ready_key']);
    $barrierDeadline = microtime(true) + 30.0;
    $startValue = false;
    while (($startValue = $redis->get((string) $payload['go_key'])) === false || $startValue === null) {
        if (microtime(true) >= $barrierDeadline) {
            task0024Fail('worker start barrier timed out');
        }
        usleep(5_000);
    }

    $startedAt = (float) $startValue;
    $deadline = $startedAt + (float) $payload['duration_seconds'];
    $completed = 0;
    $observations = [];

    foreach (($payload['indices'] ?? []) as $index) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $result = task0024WorkerOperation($payload, (int) $index, $deadline);
        $completed += $result['completed'];
        foreach ($result['observations'] as $metric => $values) {
            foreach ($values as $value) {
                $observations[$metric][] = $value;
            }
        }
    }

    fwrite(STDOUT, json_encode([
        'completed' => $completed,
        'observations' => $observations,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

/** @return never */
function task0024ParentMain(array $options): void
{
    task0024Bootstrap($options['database']);
    task0024VerifyCheckout($options['commit_sha']);

    $output = $options['output'];
    $directory = dirname($output);
    if (! is_dir($directory) || ! is_writable($directory)) {
        task0024Fail('output directory must already exist and be writable');
    }
    if (is_file($output) && ! $options['overwrite']) {
        task0024Fail('output file already exists; pass --overwrite to replace it');
    }

    $quotaRemaining = max(1000, $options['operations'] * ($options['runs'] + 2) * 4);
    $fixture = task0024CreateFixture($options['benchmark_id'], $quotaRemaining);

    config([
        'delivery.admission.concurrency_enabled' => true,
        'delivery.admission.global_concurrency_limit' => $options['concurrency'],
        'delivery.admission.workspace_concurrency_limit' => $options['concurrency'],
        'delivery.admission.reservation_ttl_seconds' => max(30, (int) ceil($options['measurement_window_seconds']) + 30),
    ]);

    if ($options['warmup_seconds'] > 0) {
        task0024RunPhase(
            $options,
            $fixture,
            'warmup',
            $options['warmup_seconds'],
            $options['operations'],
            false,
        );
    }

    $runs = [];
    for ($run = 1; $run <= $options['runs']; $run++) {
        $result = task0024RunPhase(
            $options,
            $fixture,
            'run-'.$run,
            $options['measurement_window_seconds'],
            $options['operations'],
            true,
        );
        if ($result['completed'] <= 0 || $result['observations'] === []) {
            task0024Fail('measurement run '.$run.' produced no completed observations');
        }
        $runs[] = [
            'run_id' => 'run-'.$run,
            'warmup_observations_excluded' => true,
            'elapsed_seconds' => $result['elapsed_seconds'],
            'completed_operations' => $result['completed'],
            'observations' => $result['observations'],
        ];
    }

    $environment = task0024Environment();
    foreach (['postgresql_version', 'redis_version'] as $requiredVersion) {
        if (($environment[$requiredVersion] ?? '') === '') {
            task0024Fail('unable to determine '.$requiredVersion);
        }
    }

    $evidence = [
        'schema_version' => 1,
        'benchmark_id' => $options['benchmark_id'],
        'commit_sha' => $options['commit_sha'],
        'environment' => $environment,
        'scenario' => [
            'name' => $options['scenario'] === 'delivery'
                ? 'delivery-engine-accepted-path'
                : 'delivery-engine-reconciliation-path',
            'fault_mode' => 'none',
            'seed' => $options['seed'],
            'operation_count' => $options['operations'],
            'concurrency' => $options['concurrency'],
            'warmup_seconds' => $options['warmup_seconds'],
            'measurement_window_seconds' => $options['measurement_window_seconds'],
        ],
        'runs' => $runs,
        'measurement_scope' => $options['scenario'] === 'delivery'
            ? 'internal delivery-engine enqueue/admit/accepted-outcome path'
            : 'internal delivery-engine ambiguity/reconciliation-resolution path',
        'external_provider_network_included' => false,
        'thresholds_inferred' => false,
        'delivery_owner_approval_generated' => false,
    ];

    $temporary = $output.'.tmp.'.getmypid();
    if (file_put_contents($temporary, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
        task0024Fail('unable to write temporary evidence file');
    }

    try {
        $validator = new Process([
            'python3',
            dirname(__DIR__).'/tools/delivery_benchmark_evidence.py',
            '--input',
            $temporary,
        ], dirname(__DIR__));
        $validator->setTimeout(30);
        $validator->run();
        if (! $validator->isSuccessful()) {
            task0024Fail('emitted evidence failed validator: '.trim($validator->getErrorOutput().' '.$validator->getOutput()));
        }
        $validated = json_decode($validator->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($validated) || ($validated['status'] ?? null) !== 'evidence_valid') {
            task0024Fail('validator did not return evidence_valid');
        }

        if (is_file($output) && $options['overwrite'] && ! unlink($output)) {
            task0024Fail('unable to replace existing output file');
        }
        if (! rename($temporary, $output)) {
            task0024Fail('unable to atomically move validated evidence into place');
        }

        fwrite(STDOUT, json_encode([
            'status' => 'evidence_captured',
            'benchmark_id' => $options['benchmark_id'],
            'scenario' => $options['scenario'],
            'commit_sha' => $options['commit_sha'],
            'output' => $output,
            'evidence_fingerprint' => $validated['evidence_fingerprint'] ?? null,
            'thresholds_inferred' => false,
            'delivery_owner_approval_generated' => false,
            'external_provider_network_included' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }

    exit(0);
}

$workerPayload = getenv(TASK0024_WORKER_PAYLOAD_ENV);
if (is_string($workerPayload) && $workerPayload !== '') {
    task0024WorkerMain($workerPayload);
}

$options = task0024Options();
if (array_key_exists('help', $options)) {
    fwrite(STDOUT, task0024Help().PHP_EOL);
    exit(0);
}

task0024ParentMain(task0024NormalizeParentOptions($options));
