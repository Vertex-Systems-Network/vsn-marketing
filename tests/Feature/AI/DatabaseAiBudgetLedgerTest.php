<?php

use App\Modules\AI\Infrastructure\DatabaseAiBudgetLedger;
use App\Modules\AI\Infrastructure\DatabaseAiCircuitBreaker;
use App\Modules\AI\Infrastructure\DatabaseAiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function aiBudgetWorkspace(): string
{
    $suffix = Str::lower(Str::random(10));
    $org = Organization::query()->create(['name' => "AI Org {$suffix}", 'slug' => "ai-org-{$suffix}"]);
    $workspace = Workspace::query()->create([
        'organization_id' => $org->getKey(), 'name' => "AI Workspace {$suffix}", 'slug' => "ai-workspace-{$suffix}",
    ]);

    return (string) $workspace->getKey();
}

it('denies absent workspace budgets and maintains reserved plus spent under the configured ceiling', function () {
    $workspace = aiBudgetWorkspace();
    $other = aiBudgetWorkspace();
    $ledger = new DatabaseAiBudgetLedger;

    expect($ledger->reserve($workspace, 'trace-a', 40))->toBeFalse();
    DB::table('ai_workspace_budgets')->insert([
        'workspace_id' => $workspace, 'period_utc' => gmdate('Y-m-d'), 'limit_minor' => 50,
        'reserved_minor' => 0, 'spent_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($ledger->reserve($other, 'trace-a', 1))->toBeFalse()
        ->and($ledger->reserve($workspace, 'trace-a', 40))->toBeTrue()
        ->and($ledger->reserve($workspace, 'trace-a', 40))->toBeFalse()
        ->and($ledger->reserve($workspace, 'trace-b', 11))->toBeFalse();

    $ledger->settle($workspace, 'trace-a', 12);
    expect($ledger->reserve($workspace, 'trace-b', 39))->toBeFalse()
        ->and($ledger->reserve($workspace, 'trace-b', 38))->toBeTrue();
    $row = DB::table('ai_workspace_budgets')->where('workspace_id', $workspace)->first();
    expect((int) $row->reserved_minor)->toBe(38)
        ->and((int) $row->spent_minor)->toBe(12);
});

it('rejects duplicate and oversized settlement without releasing reservation', function () {
    $workspace = aiBudgetWorkspace();
    DB::table('ai_workspace_budgets')->insert([
        'workspace_id' => $workspace, 'period_utc' => gmdate('Y-m-d'), 'limit_minor' => 30,
        'reserved_minor' => 0, 'spent_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ledger = new DatabaseAiBudgetLedger;
    expect($ledger->reserve($workspace, 'trace-c', 20))->toBeTrue();
    expect(fn () => $ledger->settle($workspace, 'trace-c', 21))->toThrow(RuntimeException::class)
        ->and((int) DB::table('ai_workspace_budgets')->where('workspace_id', $workspace)->value('reserved_minor'))->toBe(20);

    $ledger->settle($workspace, 'trace-c', 8);
    expect(fn () => $ledger->settle($workspace, 'trace-c', 8))->toThrow(RuntimeException::class);
});

it('keeps unknown routes closed and opens a failing route until its cooldown expires', function () {
    $workspace = aiBudgetWorkspace();
    $breaker = new DatabaseAiCircuitBreaker;
    expect($breaker->allows($workspace, 'route-a'))->toBeFalse();

    DB::table('ai_route_circuits')->insert([
        'workspace_id' => $workspace, 'route_id' => 'route-a',
        'failure_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($breaker->allows($workspace, 'route-a'))->toBeTrue();
    $breaker->failed($workspace, 'route-a');
    $breaker->failed($workspace, 'route-a');
    $breaker->failed($workspace, 'route-a');
    expect($breaker->allows($workspace, 'route-a'))->toBeFalse();

    DB::table('ai_route_circuits')->where('workspace_id', $workspace)->where('route_id', 'route-a')
        ->update(['open_until' => now()->subMinute()]);
    expect($breaker->allows($workspace, 'route-a'))->toBeTrue();
    $breaker->succeeded($workspace, 'route-a');
    expect((int) DB::table('ai_route_circuits')->where('workspace_id', $workspace)->value('failure_count'))->toBe(0);
});

it('records only bounded provenance references and finalizes a trace once', function () {
    $workspace = aiBudgetWorkspace();
    $telemetry = new DatabaseAiTelemetryRecorder;
    $route = ['id' => 'route-a', 'version' => 'v1'];
    $request = [
        'trace_id' => 'trace-a', 'prompt_id' => 'prompt-a', 'prompt_version' => 'v1',
        'context_manifest_sha256' => str_repeat('a', 64),
        'prompt' => 'PRIVATE PROMPT', 'context' => 'PRIVATE CONTEXT', 'output' => 'PRIVATE OUTPUT',
    ];

    expect($telemetry->begin($workspace, 'attempt-a', $route, $request))->toBeTrue()
        ->and($telemetry->begin($workspace, 'attempt-a', $route, $request))->toBeFalse();
    $telemetry->finish($workspace, 'attempt-a', 'complete', 12, 30, 10);
    expect(fn () => $telemetry->finish($workspace, 'attempt-a', 'complete', 12))->toThrow(RuntimeException::class);

    $row = (array) DB::table('ai_gateway_traces')->where('workspace_id', $workspace)->first();
    expect($row['status'])->toBe('complete')
        ->and((int) $row['cost_minor'])->toBe(12)
        ->and((int) $row['input_tokens'])->toBe(30)
        ->and((int) $row['output_tokens'])->toBe(10)
        ->and(implode(' ', array_keys($row)).json_encode($row))->not->toContain('PRIVATE');

    $request['context_manifest_sha256'] = 'invalid';
    expect($telemetry->begin($workspace, 'attempt-b', $route, $request))->toBeFalse();
});
