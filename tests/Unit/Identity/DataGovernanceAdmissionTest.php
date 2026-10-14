<?php

use App\Modules\Identity\Domain\Governance\DataSubjectActionAdmission;
use App\Modules\Identity\Domain\Governance\RegionalDataAdmission;

it('fails closed unless every downstream data surface is in an approved region', function (): void {
    $policy = new RegionalDataAdmission;
    $sameRegion = array_fill_keys(RegionalDataAdmission::SURFACES, 'region-approved');

    expect($policy->allows('region-approved', $sameRegion, true))->toBeTrue()
        ->and($policy->allows('', $sameRegion, true))->toBeFalse()
        ->and($policy->allows('region-approved', $sameRegion, false))->toBeFalse()
        ->and($policy->allows('region-approved', [...$sameRegion, 'ai' => 'disabled', 'provider' => 'disabled'], false))->toBeTrue()
        ->and($policy->allows('region-approved', array_diff_key($sameRegion, ['backups' => true]), true))->toBeFalse()
        ->and($policy->allows('region-approved', [...$sameRegion, 'logs' => 'other-region'], true))->toBeFalse()
        ->and($policy->allows('region-approved', [...$sameRegion, 'unknown' => 'region-approved'], true))->toBeFalse();
});

it('requires tenant binding and puts legal hold ahead of erasure or retention expiry', function (): void {
    $policy = new DataSubjectActionAdmission;
    expect($policy->allows('one', 'one', 'export', true, true, true, true))->toBeTrue()
        ->and($policy->allows('one', 'one', 'erase', true, true, true, true))->toBeFalse()
        ->and($policy->allows('one', 'one', 'retention_expire', true, true, true, true))->toBeFalse()
        ->and($policy->allows('one', 'one', 'erase', true, true, false, true))->toBeTrue()
        ->and($policy->allows('one', 'two', 'export', true, true, false, true))->toBeFalse()
        ->and($policy->allows('one', 'one', 'erase', false, true, false, true))->toBeFalse()
        ->and($policy->allows('one', 'one', 'erase', true, false, false, true))->toBeFalse()
        ->and($policy->allows('one', 'one', 'erase', true, true, false, false))->toBeFalse();
});
