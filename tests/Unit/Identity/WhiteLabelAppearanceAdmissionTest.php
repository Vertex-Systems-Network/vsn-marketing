<?php

use App\Modules\Identity\Domain\Governance\WhiteLabelAppearanceAdmission;

it('admits appearance tokens while refusing origin or security disclosure overrides', function (): void {
    $policy = new WhiteLabelAppearanceAdmission;
    expect($policy->allows(['display_name' => 'Client Brand', 'accent_color' => '#225588']))->toBeTrue()
        ->and($policy->allows(['display_name' => 'Client Brand', 'accent_color' => '#225588', 'logo_asset_id' => null]))->toBeTrue()
        ->and($policy->allows(['display_name' => '<script>']))->toBeFalse()
        ->and($policy->allows(['accent_color' => 'url(https://evil.example)']))->toBeFalse()
        ->and($policy->allows(['accent_color' => '#ffffff']))->toBeFalse()
        ->and($policy->allows(['origin' => 'https://client.example']))->toBeFalse()
        ->and($policy->allows(['billing_disclosure' => 'hidden']))->toBeFalse()
        ->and($policy->allows(['consent_owner' => 'agency']))->toBeFalse();
});
