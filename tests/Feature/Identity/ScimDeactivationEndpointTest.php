<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Federation\ConsumeVerifiedAssertion;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps SCIM disabled by default and admits only tenant-bound authenticated deactivation', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $other = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());
    app(WorkspaceRoleManager::class)->addMember($user, (string) $other->getKey());
    DB::table('federation_subject_bindings')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(), 'issuer' => 'https://idp.example.test',
        'subject' => 'stable-subject', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('federation_subject_bindings')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $other->getKey(),
        'user_id' => $user->getKey(), 'issuer' => 'https://idp.example.test',
        'subject' => 'stable-subject', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = str_repeat('a', 48);
    DB::table('workspace_provisioning_credentials')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->getKey(),
        'secret_hash' => hash('sha256', $token), 'enabled' => true,
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $body = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]];
    $path = '/api/scim/v2/workspaces/'.$workspace->getKey().'/Users/'.$user->getKey();
    $foreignPath = '/api/scim/v2/workspaces/'.$other->getKey().'/Users/'.$user->getKey();
    $send = fn (string $url, array $payload, string $bearer) => $this->call('PATCH', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/scim+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
    ], json_encode($payload));

    $send($path, $body, $token)->assertNotFound();
    config()->set('enterprise.scim_deactivation_enabled', true);
    $send($path, $body, 'invalid')->assertForbidden();
    $send($foreignPath, $body, $token)->assertForbidden();
    $send($path, ['schemas' => $body['schemas'], 'Operations' => [['op' => 'replace', 'path' => 'roles', 'value' => ['admin']]]], $token)
        ->assertStatus(400);
    foreach ([null, false, 17, 'invalid', [], ['invalid'], [['op' => 'replace']]] as $operations) {
        $send($path, ['schemas' => $body['schemas'], 'Operations' => $operations], $token)->assertStatus(400);
    }
    foreach (['not-json', 'null', '17', '[]', '"invalid"'] as $rawBody) {
        $this->call('PATCH', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/scim+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $rawBody)->assertStatus(400);
    }
    $this->call('PATCH', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/scim+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], json_encode($body).str_repeat(' ', 16384))->assertStatus(400);
    expect(DB::table('workspace_memberships')->where('workspace_id', $workspace->getKey())->count())->toBe(1);
    $send($path, $body, $token)->assertNoContent();
    $send($path, $body, $token)->assertNoContent();

    expect(DB::table('workspace_memberships')->where('workspace_id', $workspace->getKey())->count())->toBe(0)
        ->and(DB::table('federation_subject_bindings')->where('workspace_id', $workspace->getKey())->count())->toBe(0)
        ->and(DB::table('federation_subject_bindings')->where('workspace_id', $other->getKey())->count())->toBe(1)
        ->and(DB::table('workspace_memberships')->where('workspace_id', $other->getKey())->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'identity.provisioning.member_deactivated')->count())->toBe(1);
    $event = DB::table('audit_events')->where('action', 'identity.provisioning.member_deactivated')->first();
    expect((string) $event->evidence)->not->toContain($token);

    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());
    expect(fn () => app(ConsumeVerifiedAssertion::class)->execute(
        true, (string) $workspace->getKey(), (string) $workspace->getKey(),
        'https://idp.example.test', 'https://idp.example.test', 'client', 'client',
        'stable-subject', 'new-assertion', 1000, 1100, 1050,
    ))->toThrow(AuthorizationException::class);

    DB::table('workspace_provisioning_credentials')->where('workspace_id', $workspace->getKey())
        ->update(['expires_at' => now()->subMinute()]);
    $send($path, $body, $token)->assertForbidden();
    $send($path, ['schemas' => $body['schemas'], 'Operations' => []], $token)->assertForbidden();
});
