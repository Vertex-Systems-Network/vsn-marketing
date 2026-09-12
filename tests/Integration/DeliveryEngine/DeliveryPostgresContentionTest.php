<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run PostgreSQL contention certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0023 PostgreSQL contention certification requires the pgsql driver.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** @return array{context: TenantContext, operation_id: string, message_snapshot_id: string, recipient_snapshot_id: string, initial_version: int} */
function task0023PgFixture(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0023 PostgreSQL '.$suffix,
        'slug' => 'task-0023-pg-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0023 PostgreSQL Workspace '.$suffix,
        'slug' => 'task-0023-pg-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0023 PostgreSQL Brand '.$suffix,
        'slug' => 'task-0023-pg-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $context = new TenantContext(
        organizationId: $organizationId,
        workspaceId: $workspaceId,
        brandId: $brandId,
        actorId: 'task-0023-pg-'.$suffix,
    );
    $contact = app(CreateContact::class)->handle($context, firstName: 'PostgreSQL Contention');
    $identity = app(AddContactIdentity::class)->handle(
        $context,
        $contact->id,
        ContactIdentityType::Email,
        'task-0023-pg-'.$suffix.'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $context,
        'task-0023-pg-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'TASK-0023 PostgreSQL contention'],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $context,
        $message->id,
        $contact->id,
        $identity->id,
    );
    $operation = app(EnqueueDeliveryOperation::class)->handle(
        $context,
        $snapshots->message->id,
        $snapshots->recipient->id,
    );

    return [
        'context' => $context,
        'operation_id' => $operation->id,
        'message_snapshot_id' => $snapshots->message->id,
        'recipient_snapshot_id' => $snapshots->recipient->id,
        'initial_version' => $operation->version,
    ];
}

function task0023PgWorkerScript(): string
{
    return <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result = Illuminate\Support\Facades\DB::transaction(function () use ($payload): array {
    if (file_put_contents($payload['ready_path'], 'ready') === false) {
        throw new RuntimeException('Unable to publish PostgreSQL contention readiness marker.');
    }

    $started = hrtime(true);
    $row = Illuminate\Support\Facades\DB::table('delivery_operations')
        ->where('id', $payload['operation_id'])
        ->lockForUpdate()
        ->first();
    $waitMs = (hrtime(true) - $started) / 1_000_000;

    if ($row === null) {
        throw new RuntimeException('Delivery operation disappeared during contention certification.');
    }

    $nextVersion = ((int) $row->version) + 1;
    Illuminate\Support\Facades\DB::table('delivery_operations')
        ->where('id', $payload['operation_id'])
        ->update([
            'version' => $nextVersion,
            'updated_at' => now(),
        ]);

    return [
        'wait_ms' => $waitMs,
        'version' => $nextVersion,
    ];
});

echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
}

/** @return array{process: Process, script_path: string, ready_path: string} */
function task0023PgStartWorker(string $operationId): array
{
    $scriptPath = tempnam(sys_get_temp_dir(), 'vsn-task-0023-pg-worker-');
    if ($scriptPath === false || file_put_contents($scriptPath, task0023PgWorkerScript()) === false) {
        throw new RuntimeException('Unable to prepare PostgreSQL contention worker.');
    }

    $readyPath = sys_get_temp_dir().'/vsn-task-0023-pg-ready-'.Str::uuid();
    $payload = base64_encode(json_encode([
        'operation_id' => $operationId,
        'ready_path' => $readyPath,
    ], JSON_THROW_ON_ERROR));
    $process = new Process([PHP_BINARY, $scriptPath, $payload], base_path());
    $process->setTimeout(15);
    $process->start();

    return [
        'process' => $process,
        'script_path' => $scriptPath,
        'ready_path' => $readyPath,
    ];
}

function task0023PgAwaitReady(string $readyPath): void
{
    $deadline = microtime(true) + 5;
    while (! is_file($readyPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('PostgreSQL contention worker did not reach the lock barrier.');
        }
        usleep(10_000);
    }
}

it('serializes burst contenders on a delivery operation with bounded lock wait and no duplicate row', function () {
    $fixture = task0023PgFixture('burst');
    $workers = [];

    DB::beginTransaction();
    try {
        $locked = DB::table('delivery_operations')
            ->where('id', $fixture['operation_id'])
            ->lockForUpdate()
            ->first();
        expect($locked)->not->toBeNull();

        for ($index = 0; $index < 3; $index++) {
            $workers[] = task0023PgStartWorker($fixture['operation_id']);
        }
        foreach ($workers as $worker) {
            task0023PgAwaitReady($worker['ready_path']);
        }

        usleep(250_000);
        expect(array_reduce(
            $workers,
            static fn (bool $running, array $worker): bool => $running && $worker['process']->isRunning(),
            true,
        ))->toBeTrue();

        DB::commit();
    } catch (Throwable $error) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $error;
    }

    $results = [];
    try {
        foreach ($workers as $worker) {
            $exitCode = $worker['process']->wait();
            expect($exitCode)->toBe(0);
            $results[] = json_decode(trim($worker['process']->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($workers as $worker) {
            @unlink($worker['script_path']);
            @unlink($worker['ready_path']);
        }
    }

    expect($results)->toHaveCount(3)
        ->and(min(array_column($results, 'wait_ms')))->toBeGreaterThanOrEqual(150.0)
        ->and(max(array_column($results, 'wait_ms')))->toBeLessThan(5000.0)
        ->and((int) DB::table('delivery_operations')->where('id', $fixture['operation_id'])->value('version'))
        ->toBe($fixture['initial_version'] + 3)
        ->and(DB::table('delivery_operations')->where('id', $fixture['operation_id'])->count())->toBe(1);
});

it('rolls back stale worker mutation and replays enqueue to the same durable logical operation', function () {
    $fixture = task0023PgFixture('rollback');

    DB::beginTransaction();
    try {
        DB::table('delivery_operations')
            ->where('id', $fixture['operation_id'])
            ->lockForUpdate()
            ->first();
        DB::table('delivery_operations')
            ->where('id', $fixture['operation_id'])
            ->update([
                'version' => $fixture['initial_version'] + 50,
                'updated_at' => now(),
            ]);
        DB::rollBack();
    } catch (Throwable $error) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $error;
    }

    $replayed = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $fixture['message_snapshot_id'],
        $fixture['recipient_snapshot_id'],
    );

    expect((int) DB::table('delivery_operations')->where('id', $fixture['operation_id'])->value('version'))
        ->toBe($fixture['initial_version'])
        ->and($replayed->id)->toBe($fixture['operation_id'])
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['context']->workspaceId)
            ->where('idempotency_key', $replayed->idempotencyKey)
            ->count())->toBe(1);
});
