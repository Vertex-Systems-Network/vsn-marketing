<?php

use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

it('prepares one replay-safe provider publication attempt without persisting provider credentials', function () {
    $fixture = Task0040PublicationFixture::create('replay');
    $service = app(PublicationAttemptService::class);
    $at = new DateTimeImmutable('2026-07-15T13:30:20+00:00');

    $first = $service->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: $at,
    );
    $replayed = $service->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:21+00:00'),
    );

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->state->value)->toBe('prepared')
        ->and(DB::table('publication_attempts')->count())->toBe(1)
        ->and(DB::table('publication_attempts')->where('id', $first->id)->value('provider_connection_id'))
        ->toBe($fixture['providerConnectionId'])
        ->and(Schema::hasColumn('publication_attempts', 'secret_reference'))->toBeFalse();
});

it('fails closed when publication.create capability operation drifts after execution intent emission', function () {
    $fixture = Task0040PublicationFixture::create('operation-drift');

    DB::table('provider_capabilities')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerCapabilityId'])
        ->update([
            'operation' => 'publication.update',
            'updated_at' => new DateTimeImmutable('2026-07-15T13:30:15+00:00'),
        ]);

    expect(fn () => app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'publication.create');

    expect(DB::table('publication_attempts')->count())->toBe(0);
});

it('fails closed on provider scope loss after execution intent emission', function () {
    $fixture = Task0040PublicationFixture::create('scope-drift');

    DB::table('provider_connections')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerConnectionId'])
        ->update([
            'granted_scopes' => json_encode([], JSON_THROW_ON_ERROR),
            'updated_at' => new DateTimeImmutable('2026-07-15T13:30:15+00:00'),
        ]);

    expect(fn () => app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'connection_scope_revoked');

    expect(DB::table('publication_attempts')->count())->toBe(0);
});

it('does not allow a foreign workspace to resolve an execution intent into publication work', function () {
    $inside = Task0040PublicationFixture::create('inside');
    $outside = Task0040PublicationFixture::create('outside');

    expect(fn () => app(PublicationAttemptService::class)->prepare(
        workspaceId: $outside['context']->workspaceId,
        executionIntentId: $inside['executionIntent']->id,
        targetId: $inside['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    ))->toThrow(AuthorizationException::class, 'execution intent access denied');

    expect(DB::table('publication_attempts')->count())->toBe(0);
});
