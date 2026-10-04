<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Providers\Infrastructure\Messaging\DatabaseMessagingOperationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment required.');
    }
    if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
        throw new RuntimeException('Disposable test database required.');
    }
    $schema = 'messaging_test_'.bin2hex(random_bytes(8));
    config(['messaging.test_schema' => $schema, 'messaging.previous_search_path' => config('database.connections.pgsql.search_path')]);
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge();
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $schema = config('messaging.test_schema');
    if (! is_string($schema) || ! preg_match('/^messaging_test_[a-f0-9]{16}$/D', $schema)) {
        return;
    }
    config(['database.connections.pgsql.search_path' => config('messaging.previous_search_path')]);
    DB::purge();
    DB::statement('DROP SCHEMA '.$schema.' CASCADE');
});

it('serializes two competing first messaging reservations on PostgreSQL', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $suffix = strtolower(Str::random(12));
    $org = Organization::query()->create(['name' => 'Synthetic messaging', 'slug' => $suffix]);
    $workspace = (string) Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => 'Messaging', 'slug' => $suffix])->getKey();
    $r = new DatabaseMessagingOperationRepository;
    $fingerprint = hash('sha256', 'synthetic-payload');
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
                exit(1);
            }
            fwrite($pair[1], 'B');
            $result = $r->reserve($workspace, 'sms', 'azure', 'same-key', $fingerprint);
            fwrite($pair[1], $result->id);
            fclose($pair[1]);
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
    fclose($pair[1]);
    try {
        DB::beginTransaction();
        $first = $r->reserve($workspace, 'sms', 'azure', 'same-key', $fingerprint);
        fwrite($pair[0], 'S');
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 1))->toBe('B');
        usleep(150000);
        DB::commit();
        $otherId = stream_get_contents($pair[0]);
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)->and($otherId)->toBe($first->id)
            ->and(DB::table('messaging_operations')->where('workspace_id', $workspace)->count())->toBe(1);
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
