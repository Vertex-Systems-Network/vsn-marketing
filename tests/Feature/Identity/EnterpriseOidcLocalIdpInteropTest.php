<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Owner-approved substitute for live provider interoperability (ADR-0006):
 * a local, in-process OIDC identity provider issues RS256 ID tokens through
 * the real authorization-code + PKCE route. No provider secret is required.
 */

function localIdpToken(array $claims, $privateKey, string $kid = 'local-key'): string
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $message = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]).'.'.$encode($claims);
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

function localIdpScenario(): array
{
    test()->withoutVite();
    test()->withoutMiddleware(ThrottleRequests::class);
    URL::forceRootUrl('https://vsn.example.test');
    URL::forceScheme('https');
    test()->withServerVariables(['HTTP_HOST' => 'vsn.example.test', 'HTTPS' => 'on']);

    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $foreign = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $member = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($member, (string) $workspace->getKey());
    app(WorkspaceRoleManager::class)->addMember($outsider, (string) $foreign->getKey());
    foreach ([[$workspace, $member, 'member-subject'], [$foreign, $outsider, 'foreign-subject']] as [$w, $u, $s]) {
        DB::table('federation_subject_bindings')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $w->getKey(), 'user_id' => $u->getKey(),
            'issuer' => 'https://idp.local.test/', 'subject' => $s,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    config(['enterprise.oidc' => [
        'enabled' => true, 'workspace_id' => (string) $workspace->getKey(),
        'issuer' => 'https://idp.local.test/', 'client_id' => 'vsn-local', 'client_secret' => 'local-only',
        'redirect_uri' => route('enterprise.oidc.callback', ['workspace' => $workspace->getKey()]),
        'key_id' => 'local-key', 'public_key_pem' => openssl_pkey_get_details($private)['key'],
    ]]);

    return [$workspace, $member, $private];
}

/** Starts a login and returns the callback outcome for an ID token built from the transaction. */
function localIdpLogin(Workspace $workspace, callable $tokenFor): Illuminate\Testing\TestResponse
{
    test()->get('/auth/enterprise/'.$workspace->getKey().'/login')->assertRedirect();
    $transaction = session('enterprise_oidc');
    Http::fake(['https://idp.local.test/oauth/token' => Http::response(['id_token' => $tokenFor($transaction)], 200)]);

    return test()->get('/auth/enterprise/'.$workspace->getKey().'/callback?state='.$transaction['state'].'&code=local-code');
}

it('completes a local IdP login once and rejects replay of the same ID token', function (): void {
    [$workspace, $member, $private] = localIdpScenario();
    $claims = fn (array $t): array => [
        'iss' => 'https://idp.local.test/', 'aud' => 'vsn-local', 'sub' => 'member-subject',
        'nonce' => $t['nonce'], 'iat' => time() - 5, 'exp' => time() + 300, 'jti' => 'fixed-assertion',
    ];
    $captured = null;
    localIdpLogin($workspace, function (array $t) use ($claims, $private, &$captured): string {
        return $captured = localIdpToken($claims($t), $private);
    })->assertRedirect(route('workspaces.directory'));
    $this->assertAuthenticatedAs($member);
    auth()->guard('web')->logout();

    // The provider (or an attacker) returns the previously consumed token for a new transaction.
    localIdpLogin($workspace, fn (): string => $captured)->assertForbidden();
    $this->assertGuest();
    expect(DB::table('federation_assertion_replays')->count())->toBe(1);
});

it('rejects forged, mis-scoped and cross-tenant ID tokens from the local IdP', function (string $case): void {
    [$workspace, , $private] = localIdpScenario();
    $attacker = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

    localIdpLogin($workspace, function (array $t) use ($case, $private, $attacker): string {
        $claims = [
            'iss' => 'https://idp.local.test/', 'aud' => 'vsn-local', 'sub' => 'member-subject',
            'nonce' => $t['nonce'], 'iat' => time() - 5, 'exp' => time() + 300,
        ];
        $key = $private;
        $kid = 'local-key';
        match ($case) {
            'foreign key' => $key = $attacker,
            'unknown kid' => $kid = 'rotated-key',
            'wrong nonce' => $claims['nonce'] = 'attacker-nonce',
            'wrong issuer' => $claims['iss'] = 'https://evil.local.test/',
            'wrong audience' => $claims['aud'] = 'another-client',
            'expired' => [$claims['iat'], $claims['exp']] = [time() - 600, time() - 300],
            'foreign tenant subject' => $claims['sub'] = 'foreign-subject',
            'unbound subject' => $claims['sub'] = 'never-linked',
        };

        return localIdpToken($claims, $key, $kid);
    })->assertForbidden();

    $this->assertGuest();
    expect(DB::table('federation_assertion_replays')->count())->toBe(0);
})->with(['foreign key', 'unknown kid', 'wrong nonce', 'wrong issuer', 'wrong audience', 'expired',
    'foreign tenant subject', 'unbound subject']);

it('fails closed when the local IdP token endpoint errors', function (): void {
    [$workspace] = localIdpScenario();
    $this->get('/auth/enterprise/'.$workspace->getKey().'/login')->assertRedirect();
    $transaction = session('enterprise_oidc');
    Http::fake(['https://idp.local.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $this->get('/auth/enterprise/'.$workspace->getKey().'/callback?state='.$transaction['state'].'&code=bad')
        ->assertForbidden();
    $this->assertGuest();
});
