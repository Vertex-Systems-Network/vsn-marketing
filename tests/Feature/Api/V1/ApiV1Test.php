<?php

use App\Modules\Core\Application\Api\OpenApiV1;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: string, 2: string} user, own workspace id, foreign workspace id */
function apiV1Fixture(bool $grantContactRead = true): array
{
    $organization = Organization::query()->create(['name' => 'Org', 'slug' => 'org-'.Str::random(6)]);
    $own = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Own', 'slug' => 'own-'.Str::random(6)]);
    $foreign = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Foreign', 'slug' => 'foreign-'.Str::random(6)]);
    $user = User::query()->create(['name' => 'Amelia', 'email' => Str::random(8).'@example.test', 'password' => 'correct-horse-battery']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $own->getKey());
    if ($grantContactRead) {
        $role = $roles->createRole((string) $own->getKey(), 'marketer', 'Marketer');
        $roles->grantPermission($role, PermissionCatalog::CONTACT_READ);
        $roles->assignRole($membership, $role);
    }

    return [$user, (string) $own->getKey(), (string) $foreign->getKey()];
}

function apiV1Contact(string $workspaceId, string $name): string
{
    $id = (string) Str::uuid();
    DB::table('contacts')->insert([
        'id' => $id, 'workspace_id' => $workspaceId, 'display_name' => $name,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('FR-API-02 issues a bearer token and uses it for /me', function (): void {
    [$user] = apiV1Fixture();

    $token = $this->postJson('/api/v1/auth/tokens', [
        'email' => $user->email, 'password' => 'correct-horse-battery', 'device_name' => 'iPhone',
    ])->assertCreated()->assertJsonPath('token_type', 'Bearer')->json('token');

    expect($token)->toContain('|vsn_');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.email', $user->email);
});

it('FR-API-04 rejects bad credentials and anonymous calls with problem details', function (): void {
    [$user] = apiV1Fixture();

    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'x'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'errors' => ['email']]);

    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('lists only the caller\'s workspaces with granted permissions', function (): void {
    [$user, $own] = apiV1Fixture();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/workspaces')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own)
        ->assertJsonPath('data.0.permissions', [PermissionCatalog::CONTACT_READ]);
});

it('AC-1 scopes contacts to the X-Workspace-Id tenant and paginates by cursor', function (): void {
    [$user, $own, $foreign] = apiV1Fixture();
    $first = apiV1Contact($own, 'Ali Raza');
    apiV1Contact($own, 'Lina Ortiz');
    $foreignContact = apiV1Contact($foreign, 'Other tenant');

    $page = $this->actingAs($user, 'sanctum')
        ->withHeader('X-Workspace-Id', $own)
        ->getJson('/api/v1/contacts?page[size]=1')
        ->assertOk()->assertJsonCount(1, 'data');
    $cursor = $page->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts?page[size]=1&page[cursor]='.urlencode($cursor))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);

    $this->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts/'.$first)->assertOk()->assertJsonPath('data.display_name', 'Ali Raza');
    $this->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts/'.$foreignContact)->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts?filter[search]=Lina')->assertOk()->assertJsonCount(1, 'data');
});

it('adversarial: denies a foreign workspace header, missing header and missing permission', function (): void {
    [$user, $own, $foreign] = apiV1Fixture();
    $this->actingAs($user, 'sanctum');

    $this->withHeader('X-Workspace-Id', $foreign)->getJson('/api/v1/contacts')->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->withHeaders(['X-Workspace-Id' => ''])->getJson('/api/v1/contacts')->assertForbidden();

    [$noGrant, $noGrantWorkspace] = apiV1Fixture(grantContactRead: false);
    $this->actingAs($noGrant, 'sanctum')->withHeader('X-Workspace-Id', $noGrantWorkspace)
        ->getJson('/api/v1/contacts')->assertForbidden();
});

it('adversarial: workspace session revocation also revokes previously issued tokens', function (): void {
    [$user, $own] = apiV1Fixture();
    $token = $this->postJson('/api/v1/auth/tokens', [
        'email' => $user->email, 'password' => 'correct-horse-battery', 'device_name' => 'Pixel',
    ])->json('token');

    $this->withToken($token)->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts')->assertOk();

    app(WorkspaceSessionPolicy::class)->revoke($own, (string) $user->getKey(), (string) $user->getKey());
    app('auth')->forgetGuards();

    $this->withToken($token)->withHeader('X-Workspace-Id', $own)->getJson('/api/v1/contacts')->assertForbidden();
});

it('revokes the current token', function (): void {
    [$user] = apiV1Fixture();
    $token = $this->postJson('/api/v1/auth/tokens', [
        'email' => $user->email, 'password' => 'correct-horse-battery', 'device_name' => 'Pixel',
    ])->json('token');

    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('AC-2 documents every api.v1 route in the OpenAPI document and keeps generated files fresh', function (): void {
    $document = OpenApiV1::document();
    expect($document['openapi'])->toBe('3.1.0');

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'api.v1.')) {
            continue;
        }
        $path = substr('/'.$route->uri(), strlen('/api/v1'));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            expect($document['paths'][$path][strtolower($method)] ?? null)
                ->not->toBeNull("{$method} {$path} is missing from OpenApiV1");
        }
    }

    foreach ($document['paths'] as $operations) {
        foreach ($operations as $operation) {
            expect($operation['operationId'] ?? null)->toBeString();
        }
    }

    expect(Artisan::call('vsn:openapi', ['--check' => true]))->toBe(0, Artisan::output());
});
