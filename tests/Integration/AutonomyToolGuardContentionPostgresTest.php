<?php

use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Core\Domain\Idempotency\IdempotencyHoldRequired;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Independent tool guard workers require PostgreSQL.');
    }
});

it('blocks a second operation key before a failed rollback becomes a durable hold', function () {
    Artisan::call('migrate', ['--force' => true]);
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Tool guard contention', 'slug' => 'guard-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org,
        'name' => 'Tool guard contention', 'slug' => 'guard-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $second = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($first)->toBeArray()->and($second)->toBeArray();
    $children = [];
    DB::purge();
    try {
        $firstPid = pcntl_fork();
        expect($firstPid)->not->toBe(-1);
        if ($firstPid === 0) {
            fclose($first[0]);
            fclose($second[0]);
            fclose($second[1]);
            DB::purge();
            $exit = 1;
            try {
                app(IdempotentExecutor::class)->runGuarded(
                    $workspace, 'tool:v1', 'first-key', 'tool-hold:v1', 'arguments',
                    static function () use ($first): array {
                        fwrite($first[1], 'G');
                        if (fread($first[1], 1) !== 'C') {
                            throw new RuntimeException('Missing test release signal.');
                        }
                        throw new IdempotencyHoldRequired('Operator recovery required.');
                    },
                );
            } catch (IdempotencyHoldRequired) {
                $exit = fwrite($first[1], 'H') === 1 ? 0 : 1;
            } finally {
                fclose($first[1]);
                exit($exit);
            }
        }
        $children[] = $firstPid;
        fclose($first[1]);
        stream_set_timeout($first[0], 10);
        expect(fread($first[0], 1))->toBe('G');

        $secondPid = pcntl_fork();
        expect($secondPid)->not->toBe(-1);
        if ($secondPid === 0) {
            fclose($first[0]);
            fclose($second[0]);
            DB::purge();
            $exit = 1;
            try {
                app(IdempotentExecutor::class)->runGuarded(
                    $workspace, 'tool:v1', 'second-key', 'tool-hold:v1', 'arguments',
                    static fn (): array => ['unexpected' => true],
                );
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'Equivalent AI tool operation is already in progress.') {
                    $exit = fwrite($second[1], 'B') === 1 ? 0 : 1;
                }
            } finally {
                fclose($second[1]);
                exit($exit);
            }
        }
        $children[] = $secondPid;
        fclose($second[1]);
        stream_set_timeout($second[0], 10);
        expect(fread($second[0], 1))->toBe('B');
        expect(fwrite($first[0], 'C'))->toBe(1);
        expect(fread($first[0], 1))->toBe('H');

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $children = [];
        DB::purge();

        expect(DB::table('idempotency_keys')->where('workspace_id', $workspace)
            ->where('scope', 'tool-hold:v1')->where('idempotency_key', 'arguments')->value('status'))
            ->toBe('held')
            ->and(DB::table('idempotency_keys')->where('workspace_id', $workspace)
                ->where('idempotency_key', 'first-key')->value('status'))->toBe('held')
            ->and(DB::table('idempotency_keys')->where('workspace_id', $workspace)
                ->where('idempotency_key', 'second-key')->exists())->toBeFalse();
    } finally {
        if (is_resource($first[0])) {
            fclose($first[0]);
        }
        if (is_resource($second[0])) {
            fclose($second[0]);
        }
        foreach ($children as $pid) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        DB::purge();
    }
});
