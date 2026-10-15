<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function oidcSignedToken(array $claims, $privateKey): string
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $message = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key']).'.'.$encode($claims);
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

it('stays disabled by default and rejects a foreign workspace without an outbound request', function (): void {
    Http::fake();
    $this->get('/auth/enterprise/workspace/login')->assertForbidden();
    $this->get('/auth/enterprise/workspace/callback?state=forged&code=forged')->assertForbidden();
    Http::assertNothingSent();
});

it('binds code exchange to session state, a pinned assertion and an existing workspace member', function (): void {
    $this->withoutVite();
    URL::forceRootUrl('https://vsn.example.test');
    URL::forceScheme('https');
    $this->withServerVariables(['HTTP_HOST' => 'vsn.example.test', 'HTTPS' => 'on']);
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $foreign = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());
    DB::table('federation_subject_bindings')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(),
        'issuer' => 'https://idp.example.test/', 'subject' => 'stable-subject',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $callback = route('enterprise.oidc.callback', ['workspace' => $workspace->getKey()]);
    config(['enterprise.oidc' => [
        'enabled' => true, 'workspace_id' => (string) $workspace->getKey(),
        'issuer' => 'https://idp.example.test/', 'client_id' => 'client', 'client_secret' => 'test-only-secret',
        'redirect_uri' => $callback, 'key_id' => 'test-key',
        'public_key_pem' => openssl_pkey_get_details($private)['key'],
    ]]);
    Http::fake();
    $this->get('/auth/enterprise/'.$foreign->getKey().'/login')->assertForbidden();
    $start = $this->get('/auth/enterprise/'.$workspace->getKey().'/login');
    $start->assertRedirect();
    parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
    $transaction = session('enterprise_oidc');
    expect($query['state'])->toBe($transaction['state']);
    expect($query['nonce'])->toBe($transaction['nonce']);
    expect($query['code_challenge_method'])->toBe('S256');
    $path = '/auth/enterprise/'.$workspace->getKey().'/callback';
    $this->get($path.'?state=wrong&code=good')->assertForbidden();
    $this->get($path.'?state='.$query['state'].'&code=good')->assertForbidden();
    Http::assertNothingSent();

    $this->get('/auth/enterprise/'.$workspace->getKey().'/login')->assertRedirect();
    $transaction = session('enterprise_oidc');
    $token = oidcSignedToken([
        'iss' => 'https://idp.example.test/', 'aud' => 'client', 'sub' => 'stable-subject',
        'nonce' => $transaction['nonce'], 'iat' => time() - 10, 'exp' => time() + 300,
    ], $private);
    Http::fake(['idp.example.test/oauth/token' => Http::response(['id_token' => $token], 200)]);
    $this->get($path.'?state='.$transaction['state'].'&code=good')->assertRedirect(route('workspaces.directory'));
    $this->assertAuthenticatedAs($user);
    expect(session('identity_session_generation'))->toBeInt();
    Http::assertSent(fn ($request) => $request->url() === 'https://idp.example.test/oauth/token' &&
        $request['code_verifier'] === $transaction['verifier'] && $request['redirect_uri'] === $callback);
    expect(DB::table('federation_assertion_replays')->count())->toBe(1);
});
