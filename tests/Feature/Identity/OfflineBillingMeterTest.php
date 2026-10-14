<?php

use App\Modules\Identity\Application\Billing\RecordMeteredUsage;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('meters trusted usage once within a tenant entitlement and denies conflicting or excessive replay', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $other = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $id = (string) $workspace->getKey();
    DB::table('workspace_meter_entitlements')->insert([
        'workspace_id' => $id, 'meter' => 'offline_actions', 'limit_units' => 5,
        'used_units' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $meter = app(RecordMeteredUsage::class);
    expect($meter->execute($id, 'offline_actions', 'source-1', 3, true))->toBeTrue()
        ->and($meter->execute($id, 'offline_actions', 'source-1', 3, true))->toBeFalse();
    expect(fn () => $meter->execute($id, 'offline_actions', 'source-1', 4, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $meter->execute($id, 'offline_actions', 'source-2', 3, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $meter->execute($id, 'offline_actions', 'source-2', 1, false))->toThrow(AuthorizationException::class)
        ->and(fn () => $meter->execute((string) $other->getKey(), 'offline_actions', 'source-2', 1, true))->toThrow(AuthorizationException::class);

    expect(DB::table('workspace_meter_entitlements')->where('workspace_id', $id)->value('used_units'))->toBe(3)
        ->and(DB::table('workspace_meter_events')->where('workspace_id', $id)->count())->toBe(1);
});
