<?php

it('tenant-scopes alternate provider connection lookup before taking a row lock', function () {
    $source = file_get_contents(
        app_path('Modules/DeliveryEngine/Infrastructure/DatabaseDeliveryFailoverEligibilityRepository.php'),
    );

    expect($source)->not->toBeFalse();

    $lookupStart = strpos($source, "\$connection->table('provider_connections')");
    expect($lookupStart)->not->toBeFalse();

    $lockPosition = strpos($source, '->lockForUpdate()', $lookupStart);
    expect($lockPosition)->not->toBeFalse();

    $lookup = substr($source, $lookupStart, $lockPosition - $lookupStart);

    expect($lookup)
        ->toContain("->where('workspace_id', \$workspaceId)")
        ->toContain("->where('provider_id', \$alternateProviderId)")
        ->toContain("->where('id', \$alternateProviderConnectionId)");
});
