<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\DeliveryFairnessPolicy;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run Redis fairness concurrency certification.');
    }

    app(RedisManager::class)->connection('locks')->flushdb();
});

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function deliveryFairnessConcurrencyRun(string $script, array $payloads): array
{
    $path = tempnam(sys_get_temp_dir(), 'vsn-delivery-fairness-concurrency-');
    if ($path === false) {
        throw new RuntimeException('Unable to create fairness concurrency worker script.');
    }

    if (file_put_contents($path, $script) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to write fairness concurrency worker script.');
    }

    $processes = [];

    try {
        foreach ($payloads as $payload) {
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, $path, $encoded], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $exitCode = $process->wait();
            if ($exitCode !== 0) {
                throw new RuntimeException(trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Fairness concurrency worker returned a non-object payload.');
            }

            $results[] = $decoded;
        }

        return $results;
    } finally {
        @unlink($path);
    }
}

function deliveryFairnessConcurrencyCoordinatorAt(string $instant): RedisDeliveryAdmissionCoordinator
{
    $clock = new class(new DateTimeImmutable($instant)) implements Clock
    {
        public function __construct(private DateTimeImmutable $instant) {}

        public function now(): DateTimeImmutable
        {
            return $this->instant;
        }
    };

    return new RedisDeliveryAdmissionCoordinator(app(RedisManager::class), $clock);
}

it('preserves an equal peer workspace share under simultaneous Redis saturation', function () {
    $policy = new DeliveryFairnessPolicy;
    $workspaceA = $policy->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 0,
        globalInFlight: 0,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 4,
        workspaceConcurrencyLimit: 4,
    );
    $workspaceB = $policy->decide(
        workspaceId: 'workspace-b',
        workspaceInFlight: 0,
        globalInFlight: 0,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 4,
        workspaceConcurrencyLimit: 4,
    );

    expect($workspaceA->workspaceShare)->toBe(2)
        ->and($workspaceB->workspaceShare)->toBe(2);

    $barrierKey = 'test:delivery:fairness:'.Str::uuid();
    $payloads = [];

    for ($index = 1; $index <= 6; $index++) {
        $payloads[] = [
            'workspace_id' => 'workspace-a',
            'operation_id' => 'workspace-a-operation-'.$index,
            'workspace_limit' => $workspaceA->workspaceShare,
            'global_limit' => 4,
            'barrier_key' => $barrierKey,
            'barrier_size' => 8,
        ];
    }

    for ($index = 1; $index <= 2; $index++) {
        $payloads[] = [
            'workspace_id' => 'workspace-b',
            'operation_id' => 'workspace-b-operation-'.$index,
            'workspace_limit' => $workspaceB->workspaceShare,
            'global_limit' => 4,
            'barrier_key' => $barrierKey,
            'barrier_size' => 8,
        ];
    }

    $script = <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$redisManager = $app->make(Illuminate\Redis\RedisManager::class);
$redis = $redisManager->connection('locks');
$redis->incr($payload['barrier_key']);
$deadline = microtime(true) + 10;
while ((int) $redis->get($payload['barrier_key']) < $payload['barrier_size']) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Concurrent fairness barrier timed out.');
    }
    usleep(10000);
}
$clock = new class implements App\Modules\Core\Domain\Contracts\Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-08T15:00:00+00:00');
    }
};
$coordinator = new App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator($redisManager, $clock);
$acquired = $coordinator->tryAcquire(
    $payload['workspace_id'],
    $payload['operation_id'],
    $payload['workspace_limit'],
    $payload['global_limit'],
    30,
);
echo json_encode([
    'workspace_id' => $payload['workspace_id'],
    'operation_id' => $payload['operation_id'],
    'acquired' => $acquired,
], JSON_THROW_ON_ERROR);
PHP;

    $results = deliveryFairnessConcurrencyRun($script, $payloads);
    $workspaceAAcquired = array_values(array_filter(
        $results,
        static fn (array $row): bool => $row['workspace_id'] === 'workspace-a' && $row['acquired'] === true,
    ));
    $workspaceBAcquired = array_values(array_filter(
        $results,
        static fn (array $row): bool => $row['workspace_id'] === 'workspace-b' && $row['acquired'] === true,
    ));
    $allAcquired = array_values(array_filter(
        $results,
        static fn (array $row): bool => $row['acquired'] === true,
    ));

    expect($workspaceAAcquired)->toHaveCount(2)
        ->and($workspaceBAcquired)->toHaveCount(2)
        ->and($allAcquired)->toHaveCount(4);

    $workspaceASaturated = $policy->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 2,
        globalInFlight: 2,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 4,
        workspaceConcurrencyLimit: 4,
    );
    $workspaceBStillEligible = $policy->decide(
        workspaceId: 'workspace-b',
        workspaceInFlight: 0,
        globalInFlight: 2,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 4,
        workspaceConcurrencyLimit: 4,
    );

    expect($workspaceASaturated->admitted)->toBeFalse()
        ->and($workspaceASaturated->reason)->toBe('workspace_fair_share_exhausted')
        ->and($workspaceBStillEligible->admitted)->toBeTrue();
});

it('keeps concurrent duplicate Redis reservations idempotent without consuming peer capacity', function () {
    $barrierKey = 'test:delivery:redis-idempotency:'.Str::uuid();
    $payload = [
        'workspace_id' => 'workspace-a',
        'operation_id' => 'same-logical-operation',
        'workspace_limit' => 1,
        'global_limit' => 1,
        'barrier_key' => $barrierKey,
        'barrier_size' => 4,
    ];

    $script = <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$redisManager = $app->make(Illuminate\Redis\RedisManager::class);
$redis = $redisManager->connection('locks');
$redis->incr($payload['barrier_key']);
$deadline = microtime(true) + 10;
while ((int) $redis->get($payload['barrier_key']) < $payload['barrier_size']) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Concurrent Redis idempotency barrier timed out.');
    }
    usleep(10000);
}
$clock = new class implements App\Modules\Core\Domain\Contracts\Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-08T15:00:00+00:00');
    }
};
$coordinator = new App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator($redisManager, $clock);
$acquired = $coordinator->tryAcquire(
    $payload['workspace_id'],
    $payload['operation_id'],
    $payload['workspace_limit'],
    $payload['global_limit'],
    30,
);
echo json_encode(['acquired' => $acquired], JSON_THROW_ON_ERROR);
PHP;

    $results = deliveryFairnessConcurrencyRun($script, [$payload, $payload, $payload, $payload]);

    expect(array_filter($results, static fn (array $row): bool => $row['acquired'] === true))->toHaveCount(4)
        ->and(deliveryFairnessConcurrencyCoordinatorAt('2026-09-08T15:00:00+00:00')
            ->tryAcquire('workspace-b', 'peer-operation', 1, 1, 30))->toBeFalse();
});
