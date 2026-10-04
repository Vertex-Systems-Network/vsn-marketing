<?php

use App\Modules\Experiments\Domain\CampaignExperimentMatrix;

function matrixArm(string $content, string $time, string $audience): array
{
    return ['content' => $content, 'time' => $time, 'audience' => $audience];
}

it('pins a bounded complete factorial matrix independently of arm ordering', function () {
    $a = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $b = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $s = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    $arms = [
        'control' => matrixArm($a, '2026-10-03T09:00:00Z', $s),
        'treatment' => matrixArm($b, '2026-10-03T09:00:00Z', $s),
    ];
    expect((new CampaignExperimentMatrix($arms))->fingerprint())
        ->toBe((new CampaignExperimentMatrix(array_reverse($arms, true)))->fingerprint());
    expect(fn () => new CampaignExperimentMatrix([
        ...$arms, 'incomplete' => matrixArm($a, '2026-10-03T10:00:00Z', $s),
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CampaignExperimentMatrix(['control' => matrixArm($a, '2026-10-03T09:00:00+00:00', $s)]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CampaignExperimentMatrix(array_fill_keys(array_map(fn ($n) => 'arm'.$n, range(1, 17)), $arms['control'])))
        ->toThrow(InvalidArgumentException::class);
});
