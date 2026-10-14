<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Federation\ConsumeVerifiedAssertion;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('consumes a verified bound assertion once and refuses sibling tenants and removed members', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    $membership = app(WorkspaceRoleManager::class)->addMember($user, (string) $first->getKey());
    $workspaceId = (string) $first->getKey();
    DB::table('federation_subject_bindings')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'user_id' => $user->getKey(),
        'issuer' => 'https://idp.example.test', 'subject' => 'stable-subject',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $consume = app(ConsumeVerifiedAssertion::class);
    $args = [true, $workspaceId, $workspaceId, 'https://idp.example.test', 'https://idp.example.test',
        'vsn-client', 'vsn-client', 'stable-subject', 'assertion-1', 1000, 1100, 1050];
    expect($consume->execute(...$args))->toBe((string) $user->getKey());
    expect(fn () => $consume->execute(...$args))->toThrow(AuthorizationException::class);
    expect(DB::table('federation_assertion_replays')->where('workspace_id', $workspaceId)->count())->toBe(1);
    expect(DB::table('audit_events')->where('workspace_id', $workspaceId)
        ->where('action', 'identity.federation.assertion_consumed')->count())->toBe(1);

    $foreign = $args;
    $foreign[1] = (string) $second->getKey();
    $foreign[2] = (string) $second->getKey();
    $foreign[8] = 'assertion-2';
    expect(fn () => $consume->execute(...$foreign))->toThrow(AuthorizationException::class);
    DB::table('workspace_memberships')->where('id', $membership)->delete();
    $args[8] = 'assertion-3';
    expect(fn () => $consume->execute(...$args))->toThrow(AuthorizationException::class);
    expect(DB::table('federation_assertion_replays')->count())->toBe(1);
});

it('rejects unverified, wrong-audience and expired claims before recording a replay', function (): void {
    $consume = app(ConsumeVerifiedAssertion::class);
    $args = [false, 'workspace', 'workspace', 'issuer', 'issuer', 'client', 'client', 'subject', 'assertion', 1000, 1100, 1050];
    expect(fn () => $consume->execute(...$args))->toThrow(AuthorizationException::class);
    $args[0] = true;
    $args[6] = 'other-client';
    expect(fn () => $consume->execute(...$args))->toThrow(AuthorizationException::class);
    $args[6] = 'client';
    $args[11] = 1100;
    expect(fn () => $consume->execute(...$args))->toThrow(AuthorizationException::class);
    expect(DB::table('federation_assertion_replays')->count())->toBe(0);
});
