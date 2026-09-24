<?php

use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 PostgreSQL persistence tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 publication-attempt persistence certification requires PostgreSQL.');
    }
});

it('preserves one immutable canonical attempt across replay and re-entrant migration', function () {
    $fixture = Task0040PublicationFixture::create('pgsql');
    $service = app(PublicationAttemptService::class);

    $attempt = $service->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $replayed = $service->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:25+00:00'),
    );

    expect($replayed->id)->toBe($attempt->id)
        ->and($replayed->attemptHash)->toBe($attempt->attemptHash)
        ->and(DB::table('publication_attempts')->count())->toBe(1);

    $migration = require database_path('migrations/2026_09_24_000001_create_publication_attempt_foundation_tables.php');
    $migration->up();

    expect(DB::table('publication_attempts')->count())->toBe(1);

    expect(fn () => DB::table('publication_attempts')
        ->where('id', $attempt->id)
        ->update([
            'target_id' => $fixture['providerConnectionId'],
            'state_version' => 2,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        ]))
        ->toThrow(QueryException::class);
});

it('enforces monotonic publication-attempt state transitions in PostgreSQL', function () {
    $fixture = Task0040PublicationFixture::create('state-machine');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    DB::table('publication_attempts')
        ->where('id', $attempt->id)
        ->update([
            'state' => 'dispatching',
            'state_version' => 2,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        ]);

    expect(DB::table('publication_attempts')->where('id', $attempt->id)->value('state'))
        ->toBe('dispatching');

    expect(fn () => DB::table('publication_attempts')
        ->where('id', $attempt->id)
        ->update([
            'state' => 'prepared',
            'state_version' => 3,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
        ]))
        ->toThrow(QueryException::class);
});
