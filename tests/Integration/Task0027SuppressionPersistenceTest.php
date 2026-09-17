<?php

use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use App\Modules\Consent\Infrastructure\Unsubscribe\DatabaseUnsubscribeTokenRepository;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0027 PostgreSQL tests.');
    }
});

function task0027PersistenceTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0027 Persistence '.$suffix,
        'slug' => 'task0027-persistence-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0027 Workspace '.$suffix,
        'slug' => 'task0027-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0027 Brand '.$suffix,
        'slug' => 'task0027-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'task0027-persistence-'.$suffix,
        ),
    ];
}

function task0027OpaqueToken(array $fixture, string $contactId, string $rawToken): OpaqueUnsubscribeToken
{
    return new OpaqueUnsubscribeToken(
        value: $rawToken,
        scope: new UnsubscribeScope(
            workspaceId: $fixture['workspace_id'],
            contactId: $contactId,
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-10-17T10:00:00+00:00'),
    );
}

it('persists only unsubscribe token digests and resolves the immutable scoped token on PostgreSQL', function () {
    $fixture = task0027PersistenceTenant('token');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Token Scope');
    $rawToken = str_repeat('a', 43);
    $token = task0027OpaqueToken($fixture, $contact->id, $rawToken);
    $repository = app(DatabaseUnsubscribeTokenRepository::class);
    $id = (string) Str::uuid();

    $repository->persist($id, $token, ['issuer' => 'task0027']);
    $resolved = $repository->resolve($rawToken);
    $stored = DB::table('unsubscribe_token_scopes')->where('id', $id)->first();

    expect($resolved)->not->toBeNull()
        ->and($resolved?->digest)->toBe(hash('sha256', $rawToken))
        ->and($resolved?->scope->workspaceId)->toBe($fixture['workspace_id'])
        ->and((string) $stored->token_digest)->toBe(hash('sha256', $rawToken))
        ->and(json_encode($stored))->not->toContain($rawToken);
});

it('accepts exact unsubscribe token replay and rejects conflicting replay on PostgreSQL', function () {
    $fixture = task0027PersistenceTenant('replay');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Replay');
    $token = task0027OpaqueToken($fixture, $contact->id, str_repeat('b', 43));
    $repository = app(DatabaseUnsubscribeTokenRepository::class);
    $id = (string) Str::uuid();

    $repository->persist($id, $token, ['issuer' => 'task0027']);
    $repository->persist($id, $token, ['issuer' => 'task0027']);

    expect(DB::table('unsubscribe_token_scopes')->where('id', $id)->count())->toBe(1)
        ->and(fn () => $repository->persist($id, $token, ['issuer' => 'changed']))
        ->toThrow(InvalidArgumentException::class);
});

it('fails closed when the same opaque digest is ambiguous across workspaces', function () {
    $first = task0027PersistenceTenant('ambiguous-a');
    $second = task0027PersistenceTenant('ambiguous-b');
    $firstContact = app(CreateContact::class)->handle($first['context'], firstName: 'First');
    $secondContact = app(CreateContact::class)->handle($second['context'], firstName: 'Second');
    $rawToken = str_repeat('c', 43);
    $repository = app(DatabaseUnsubscribeTokenRepository::class);

    $repository->persist((string) Str::uuid(), task0027OpaqueToken($first, $firstContact->id, $rawToken));
    $repository->persist((string) Str::uuid(), task0027OpaqueToken($second, $secondContact->id, $rawToken));

    expect(fn () => $repository->resolve($rawToken))->toThrow(InvalidArgumentException::class);
});

it('enforces workspace scope and append-only token evidence on PostgreSQL', function () {
    $first = task0027PersistenceTenant('scope-a');
    $second = task0027PersistenceTenant('scope-b');
    $outsideContact = app(CreateContact::class)->handle($second['context'], firstName: 'Outside');
    $repository = app(DatabaseUnsubscribeTokenRepository::class);
    $foreignToken = task0027OpaqueToken($first, $outsideContact->id, str_repeat('d', 43));

    expect(fn () => $repository->persist((string) Str::uuid(), $foreignToken))
        ->toThrow(AuthorizationException::class);

    $insideContact = app(CreateContact::class)->handle($first['context'], firstName: 'Inside');
    $id = (string) Str::uuid();
    $repository->persist($id, task0027OpaqueToken($first, $insideContact->id, str_repeat('e', 43)));

    expect(fn () => DB::transaction(
        fn () => DB::table('unsubscribe_token_scopes')->where('id', $id)->update(['purpose' => 'transactional']),
    ))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(
        fn () => DB::table('unsubscribe_token_scopes')->where('id', $id)->delete(),
    ))->toThrow(QueryException::class);
});

it('keeps accepted suppression immediately authoritative and replay safe on PostgreSQL', function () {
    $fixture = task0027PersistenceTenant('suppression');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Suppressed');
    $repository = app(DatabaseSuppressionRepository::class);
    $at = new DateTimeImmutable('2026-09-17T11:00:00+00:00');
    $record = new SuppressionRecord(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspace_id'],
        contactId: $contact->id,
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058_one_click',
        sourceVersion: 'RFC8058',
        providerKey: null,
        idempotencyKey: 'rfc8058:'.str_repeat('f', 64),
        observedAt: $at,
        effectiveAt: $at,
        freshUntil: null,
        immutableEvidence: ['transport' => 'rfc8058', 'token_digest' => str_repeat('f', 64)],
    );

    $repository->appendSuppression($record);
    $repository->appendSuppression($record);

    expect($repository->isSuppressed($fixture['workspace_id'], $contact->id, 'email', 'marketing', $at))->toBeTrue()
        ->and(DB::table('suppression_records')->where('idempotency_key', $record->idempotencyKey)->count())->toBe(1);
});
