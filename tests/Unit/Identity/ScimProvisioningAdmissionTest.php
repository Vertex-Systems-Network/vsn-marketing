<?php

use App\Modules\Identity\Domain\Provisioning\ScimProvisioningAdmission;

it('admits only authenticated enabled workspace-bound scoped provisioning', function (): void {
    $policy = new ScimProvisioningAdmission;

    expect($policy->allows('ws-1', 'ws-1', 'user.deactivate', ['user.deactivate'], true, true))->toBeTrue()
        ->and($policy->allows('ws-1', 'ws-2', 'user.deactivate', ['user.deactivate'], true, true))->toBeFalse()
        ->and($policy->allows('', '', 'user.deactivate', ['user.deactivate'], true, true))->toBeFalse()
        ->and($policy->allows('ws-1', 'ws-1', 'user.deactivate', ['user.deactivate'], false, true))->toBeFalse()
        ->and($policy->allows('ws-1', 'ws-1', 'user.deactivate', ['user.deactivate'], true, false))->toBeFalse()
        ->and($policy->allows('ws-1', 'ws-1', 'user.deactivate', ['user.create'], true, true))->toBeFalse();
});

it('does not accept role elevation or unknown operations as provisioning actions', function (): void {
    $policy = new ScimProvisioningAdmission;

    expect($policy->allows('ws-1', 'ws-1', 'role.owner', ['role.owner'], true, true))->toBeFalse()
        ->and($policy->allows('ws-1', 'ws-1', 'billing.manage', ['billing.manage'], true, true))->toBeFalse()
        ->and($policy->allows('ws-1', 'ws-1', 'group.write', ['group.write'], true, true))->toBeFalse();
});
