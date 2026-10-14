<?php

use App\Modules\Identity\Domain\Federation\FederationClaimAdmission;

it('binds a verified assertion to its configured workspace issuer audience subject and lifetime', function (): void {
    $gate = new FederationClaimAdmission;
    $claim = [true, 'ws-1', 'ws-1', 'https://idp.example.test', 'https://idp.example.test', 'vsn-client', 'vsn-client', 'idp-user-1', 1000, 1300, 1100];

    expect($gate->allows(...$claim))->toBeTrue();

    foreach ([
        0 => false,
        2 => 'ws-2',
        4 => 'https://other.example.test',
        6 => 'other-client',
        7 => '',
        8 => 1200,
        9 => 1100,
    ] as $offset => $replacement) {
        $altered = $claim;
        $altered[$offset] = $replacement;
        expect($gate->allows(...$altered))->toBeFalse();
    }

    $longLived = $claim;
    $longLived[9] = 5000;
    expect($gate->allows(...$longLived))->toBeFalse();
});
