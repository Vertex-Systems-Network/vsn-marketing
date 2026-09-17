<?php

use App\Modules\Consent\Presentation\Http\Controllers\OneClickUnsubscribeController;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::post('/_task0027/one-click', [OneClickUnsubscribeController::class, 'store']);
});

function task0027HttpFixture(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0027 HTTP '.$suffix,
        'slug' => 'task0027-http-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0027 HTTP Workspace '.$suffix,
        'slug' => 'task0027-http-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0027 HTTP Brand '.$suffix,
        'slug' => 'task0027-http-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $context = new TenantContext(
        organizationId: $organizationId,
        workspaceId: $workspaceId,
        brandId: $brandId,
        actorId: 'task0027-http-'.$suffix,
    );
    $contact = app(CreateContact::class)->handle($context, firstName: 'One Click');

    return ['workspace_id' => $workspaceId, 'contact_id' => $contact->id];
}

function task0027SeedHttpToken(array $fixture, string $rawToken, ?string $expiresAt = null): string
{
    $id = (string) Str::uuid();
    DB::table('unsubscribe_token_scopes')->insert([
        'id' => $id,
        'workspace_id' => $fixture['workspace_id'],
        'contact_id' => $fixture['contact_id'],
        'channel' => 'email',
        'purpose' => 'marketing',
        'scope_type' => 'list',
        'scope_key' => 'newsletter',
        'token_digest' => hash('sha256', $rawToken),
        'issued_at' => '2026-09-17 09:00:00+00',
        'expires_at' => $expiresAt,
        'metadata' => json_encode(['issuer' => 'task0027-http'], JSON_THROW_ON_ERROR),
        'created_at' => '2026-09-17 09:00:00+00',
    ]);

    return $id;
}

function task0027PostOneClick(object $test, string $rawToken)
{
    return $test->call(
        'POST',
        '/_task0027/one-click?token='.rawurlencode($rawToken),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        'List-Unsubscribe=One-Click',
    );
}

it('accepts a public RFC8058 POST without browser session or authentication state', function () {
    $fixture = task0027HttpFixture('accept');
    $rawToken = str_repeat('h', 43);
    $scopeId = task0027SeedHttpToken($fixture, $rawToken);

    task0027PostOneClick($this, $rawToken)->assertNoContent();

    $stored = DB::table('suppression_records')->where('id', $scopeId)->first();
    expect($stored)->not->toBeNull()
        ->and((string) $stored->authority_type)->toBe('unsubscribe')
        ->and((string) $stored->idempotency_key)->toBe('rfc8058:'.hash('sha256', $rawToken))
        ->and(json_encode($stored))->not->toContain($rawToken);
});

it('treats duplicate one-click delivery as deterministic idempotent replay', function () {
    $fixture = task0027HttpFixture('replay');
    $rawToken = str_repeat('r', 43);
    task0027SeedHttpToken($fixture, $rawToken);

    task0027PostOneClick($this, $rawToken)->assertNoContent();
    task0027PostOneClick($this, $rawToken)->assertNoContent();

    expect(DB::table('suppression_records')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('idempotency_key', 'rfc8058:'.hash('sha256', $rawToken))
        ->count())->toBe(1);
});

it('fails closed for malformed or unknown token material without leaking request details', function () {
    $fixture = task0027HttpFixture('invalid');

    task0027PostOneClick($this, 'not-a-valid-token')->assertStatus(400)->assertContent('');
    task0027PostOneClick($this, str_repeat('u', 43))->assertStatus(400)->assertContent('');

    expect(DB::table('suppression_records')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(0);
});

it('rejects expired token scope before creating suppression evidence', function () {
    $fixture = task0027HttpFixture('expired');
    $rawToken = str_repeat('e', 43);
    task0027SeedHttpToken($fixture, $rawToken, '2026-09-17 09:00:01+00');

    $this->travelTo(new DateTimeImmutable('2026-09-17T10:00:00+00:00'));
    task0027PostOneClick($this, $rawToken)->assertStatus(400);

    expect(DB::table('suppression_records')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(0);
});
