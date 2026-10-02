<?php

use App\Modules\Experiments\Application\ExperimentAssignments;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentEligibility;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function experimentWorkspace(): array
{
    $organization = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $organization, 'name' => 'Experiment Test',
        'slug' => 'exp-'.Str::random(12), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspace, 'organization_id' => $organization,
        'name' => 'Experiment Workspace', 'slug' => 'exp-'.Str::random(12), 'created_at' => now(), 'updated_at' => now()]);

    return [$organization, $workspace];
}

function experimentService(bool $witness = true, bool $permission = true, ?string $key = null, bool $eligible = true): ExperimentAssignments
{
    $access = new class($permission) implements ExperimentAccess
    {
        public function __construct(private bool $allowed) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->allowed && in_array($permission, ['campaign.create', 'campaign.approve', 'campaign.read'], true);
        }
    };
    $verifier = new class($witness) implements ExposureVerifier
    {
        public function __construct(private bool $valid) {}

        public function witnessed(TenantContext $actor, string $experimentId, string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): bool
        {
            return $this->valid && str_starts_with($reference, 'render:');
        }
    };

    $eligibility = new class($eligible) implements ExperimentEligibility
    {
        public function __construct(private bool $allowed) {}

        public function allows(TenantContext $actor, string $unitKind, string $unitId): bool
        {
            return $this->allowed && $unitKind === 'contact' && $unitId !== '';
        }
    };

    return new ExperimentAssignments(new ExperimentAllocator($key ?? str_repeat('k', 32)), $access, $verifier, $eligibility);
}

it('freezes scoped plan, replays assignment, and requires a separate witness for exposure', function () {
    [$org, $workspace] = experimentWorkspace();
    $owner = new TenantContext($org, $workspace, null, 'owner');
    $reviewer = new TenantContext($org, $workspace, null, 'reviewer');
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'subject-line', 'contact',
        ['control' => 5000, 'variant' => 4000, 'holdout' => 1000], 'control', 'holdout');
    $service = experimentService();
    $service->create($owner, $plan);
    expect(fn () => $service->assign($owner, $plan->id, 'contact-1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->activate($owner, $plan->id))->toThrow(InvalidArgumentException::class);
    $service->activate($reviewer, $plan->id);
    $a = $service->assign($owner, $plan->id, 'contact-1');
    expect($service->assign($owner, $plan->id, 'contact-1'))->toBe($a)
        ->and(DB::table('experiment_assignments')->count())->toBe(1)
        ->and(DB::table('experiment_assignments')->first()->subject_key)->not->toContain('contact-1');
    $time = new DateTimeImmutable('now');
    expect(fn () => experimentService(false)->recordExposure($owner, $plan->id, $a['id'], $a['variant'], 'render:a', $time))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('experiment_exposures')->count())->toBe(0);
    if (! $a['holdout']) {
        expect(fn () => $service->recordExposure($owner, $plan->id, $a['id'], $a['variant'], 'render:early', $time->modify('-1 day')))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => experimentService(true, true, str_repeat('z', 32))->recordExposure(
                $owner, $plan->id, $a['id'], $a['variant'], 'render:rotated', $time,
            ))->toThrow(InvalidArgumentException::class);
        $id = $service->recordExposure($owner, $plan->id, $a['id'], $a['variant'], 'render:a', $time);
        expect($service->recordExposure($owner, $plan->id, $a['id'], $a['variant'], 'render:a', $time))->toBe($id)
            ->and(DB::table('experiment_exposures')->count())->toBe(1);
    }
    expect(fn () => experimentService(true, true, str_repeat('z', 32))->assign($owner, $plan->id, 'contact-1'))
        ->toThrow(InvalidArgumentException::class);
});

it('denies foreign workspace and brand access, missing permission, and conflicting layer assignment', function () {
    [$org, $workspace] = experimentWorkspace();
    [, $foreignWorkspace] = experimentWorkspace();
    $owner = new TenantContext($org, $workspace, null, 'owner');
    $reviewer = new TenantContext($org, $workspace, null, 'reviewer');
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'shared-layer', 'contact',
        ['control' => 5000, 'variant' => 5000], 'control', null);
    $service = experimentService();
    $service->create($owner, $plan);
    $service->activate($reviewer, $plan->id);
    expect(fn () => $service->assign(new TenantContext($org, $foreignWorkspace, null, 'other'), $plan->id, 'unit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->assign(new TenantContext($org, $workspace, (string) Str::uuid(), 'other'), $plan->id, 'unit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => experimentService(true, false)->assign($owner, $plan->id, 'unit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => experimentService(true, true, null, false)->assign($owner, $plan->id, 'unit'))
        ->toThrow(InvalidArgumentException::class);
    $service->assign($owner, $plan->id, 'unit');
    $other = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'shared-layer', 'contact',
        ['control' => 5000, 'variant' => 5000], 'control', null);
    expect(fn () => experimentService(true, true, str_repeat('z', 32))->create($owner, $other))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('experiments')->where('id', $other->id)->exists())->toBeFalse();
    $service->create($owner, $other);
    $service->activate($reviewer, $other->id);
    expect(fn () => $service->assign($owner, $other->id, 'unit'))->toThrow(RuntimeException::class)
        ->and(DB::table('experiment_assignments')->count())->toBe(1);
});

it('keeps holdout unexposed and rejects backdated or cross-brand evidence', function () {
    [$org, $workspace] = experimentWorkspace();
    $brand = (string) Str::uuid();
    DB::table('brands')->insert(['id' => $brand, 'workspace_id' => $workspace,
        'name' => 'Experiment Brand', 'slug' => 'exp-brand-'.Str::random(8),
        'created_at' => now(), 'updated_at' => now()]);
    $owner = new TenantContext($org, $workspace, $brand, 'owner');
    $reviewer = new TenantContext($org, $workspace, $brand, 'reviewer');
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, $brand, 'brand-layer', 'contact',
        ['control' => 1, 'holdout' => 9999], 'control', 'holdout');
    $service = experimentService();
    $service->create($owner, $plan);
    $service->activate($reviewer, $plan->id);
    $holdout = null;
    for ($i = 0; $i < 20; $i++) {
        $candidate = $service->assign($owner, $plan->id, 'unit-'.$i);
        if ($candidate['holdout']) {
            $holdout = $candidate;
            break;
        }
    }
    expect($holdout)->not->toBeNull();
    $at = new DateTimeImmutable('now');
    expect(fn () => $service->recordExposure($owner, $plan->id, $holdout['id'], $holdout['variant'], 'render:holdout', $at))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->recordExposure(new TenantContext($org, $workspace, null, 'owner'), $plan->id,
            $holdout['id'], $holdout['variant'], 'render:foreign-brand', $at))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('experiment_exposures')->count())->toBe(0);
});
