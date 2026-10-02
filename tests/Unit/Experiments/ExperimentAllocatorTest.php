<?php

use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentPlan;
use Illuminate\Support\Str;

it('allocates stably without exposing unit identifiers and rejects invalid plans', function () {
    $plan = new ExperimentPlan((string) Str::uuid(), (string) Str::uuid(), null, 'content', 'contact',
        ['variant' => 4000, 'control' => 5000, 'holdout' => 1000], 'control', 'holdout');
    $allocator = new ExperimentAllocator(str_repeat('k', 32));
    $subject = $allocator->subjectKey($plan, 'sensitive-contact-reference');
    expect($subject)->toHaveLength(64)
        ->and($subject)->not->toContain('sensitive-contact')
        ->and($allocator->variant($plan, 'sensitive-contact-reference'))->toBe($allocator->variant($plan, 'sensitive-contact-reference'))
        ->and($allocator->subjectKey($plan, 'sensitive-contact-reference'))->not->toBe((new ExperimentAllocator(str_repeat('z', 32)))->subjectKey($plan, 'sensitive-contact-reference'));
    expect(fn () => new ExperimentPlan($plan->id, $plan->workspaceId, null, 'content', 'contact', ['a' => 5000, 'b' => 4000], 'a', null))
        ->toThrow(InvalidArgumentException::class);
});
