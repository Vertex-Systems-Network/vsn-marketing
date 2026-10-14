<?php

use App\Modules\Identity\Application\Recovery\OfflineRecoveryExercise;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('checks encrypted fixture integrity, tenant scope and completeness before recording recovery timing', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $exercise = app(OfflineRecoveryExercise::class);
    $workspaceId = (string) $first->getKey();
    $sealed = $exercise->seal($workspaceId, ['one' => 'fixture', 'two' => 'fixture']);

    expect(fn () => $exercise->rehearse((string) $second->getKey(), $sealed, 2))->toThrow(AuthorizationException::class)
        ->and(fn () => $exercise->rehearse($workspaceId, $sealed, 3))->toThrow(AuthorizationException::class)
        ->and(fn () => $exercise->rehearse($workspaceId, substr($sealed, 0, -2).'xx', 2))->toThrow(AuthorizationException::class);

    $envelope = json_decode(Crypt::decryptString($sealed), true);
    $envelope['digest'] = str_repeat('0', 64);
    expect(fn () => $exercise->rehearse($workspaceId, Crypt::encryptString(json_encode($envelope)), 2))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('offline_recovery_exercises')->count())->toBe(0);

    $result = $exercise->rehearse($workspaceId, $sealed, 2);
    expect($result['fixture_items'])->toBe(2)
        ->and($result['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and(DB::table('offline_recovery_exercises')->where('workspace_id', $workspaceId)->count())->toBe(1);
});
