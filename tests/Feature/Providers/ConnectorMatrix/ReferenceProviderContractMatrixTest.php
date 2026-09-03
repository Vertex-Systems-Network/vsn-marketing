<?php

/** @return array<string, array<string, mixed>> */
function task0017ReferenceProviderMatrix(): array
{
    /** @var array<string, array<string, mixed>> $matrix */
    $matrix = require base_path('tests/Fixtures/Providers/ConnectorMatrix/reference-provider-matrix.php');

    return $matrix;
}

it('pins the deliberately diverse TASK-0017 reference connector set', function () {
    $matrix = task0017ReferenceProviderMatrix();

    expect(array_keys($matrix))->toBe(['amazon-ses', 'brevo', 'gmail-api'])
        ->and($matrix['amazon-ses']['connector_class'])->toBe('delivery')
        ->and($matrix['brevo']['connector_class'])->toBe('delivery_marketing_platform')
        ->and($matrix['gmail-api']['connector_class'])->toBe('mailbox');
});

it('keeps authentication evidence separate from executable readiness and raw credentials out of fixtures', function () {
    $matrix = task0017ReferenceProviderMatrix();
    $serialized = json_encode($matrix, JSON_THROW_ON_ERROR);

    foreach ($matrix as $provider => $contract) {
        expect($contract['secret_reference_required'], $provider)->toBeTrue()
            ->and($contract['readiness_dimensions'], $provider)->toBeArray()->not->toBeEmpty()
            ->and($contract['auth_family'], $provider)->toBeString()->not->toBe('');
    }

    expect($serialized)
        ->not->toContain('xkeysib-')
        ->not->toContain('AKIA')
        ->not->toContain('Bearer ')
        ->not->toContain('-----BEGIN PRIVATE KEY-----');
});

it('models provider quota pressure as multidimensional runtime provenance instead of one core constant', function () {
    $matrix = task0017ReferenceProviderMatrix();

    foreach ($matrix as $provider => $contract) {
        $dimensions = $contract['quota_dimensions'];

        expect($dimensions, $provider)->toBeArray()->not->toBeEmpty();

        foreach ($dimensions as $dimension) {
            expect($dimension['scope'], $provider)->toBeString()->not->toBe('')
                ->and($dimension['unit'], $provider)->toBeString()->not->toBe('')
                ->and($dimension['window'], $provider)->toBeString()->not->toBe('')
                ->and($dimension['runtime'], $provider)->toBeTrue();
        }
    }

    expect($matrix['amazon-ses']['quota_dimensions'])->toHaveCount(2)
        ->and($matrix['brevo']['quota_dimensions'])->toHaveCount(2)
        ->and($matrix['gmail-api']['quota_dimensions'])->toHaveCount(2);
});

it('fails closed on sandbox success and never upgrades test acceptance into delivery or production readiness', function () {
    $matrix = task0017ReferenceProviderMatrix();

    expect($matrix['amazon-ses']['sandbox']['supported'])->toBeTrue()
        ->and($matrix['amazon-ses']['sandbox']['requires_restricted_targets'])->toBeTrue()
        ->and($matrix['amazon-ses']['sandbox']['acceptance_means_delivery'])->toBeFalse()
        ->and($matrix['amazon-ses']['sandbox']['production_readiness_evidence'])->toBeFalse()
        ->and($matrix['brevo']['sandbox']['supported'])->toBeTrue()
        ->and($matrix['brevo']['sandbox']['request_validation_only'])->toBeTrue()
        ->and($matrix['brevo']['sandbox']['creates_delivery_log'])->toBeFalse()
        ->and($matrix['brevo']['sandbox']['acceptance_means_delivery'])->toBeFalse()
        ->and($matrix['brevo']['sandbox']['production_readiness_evidence'])->toBeFalse()
        ->and($matrix['gmail-api']['sandbox']['supported'])->toBeFalse()
        ->and($matrix['gmail-api']['sandbox']['production_readiness_evidence'])->toBeFalse();
});

it('keeps Gmail mailbox sending distinct from bulk marketing delivery', function () {
    $gmail = task0017ReferenceProviderMatrix()['gmail-api'];

    expect($gmail['send']['supported'])->toBeTrue()
        ->and($gmail['send']['mailbox_only'])->toBeTrue()
        ->and($gmail['send']['bulk_marketing_semantics'])->toBeFalse()
        ->and($gmail['send']['mime_rfc2822_base64url'])->toBeTrue()
        ->and($gmail['least_privilege_send_scope'])->toBe('https://www.googleapis.com/auth/gmail.send')
        ->and($gmail['unsupported_phase_capabilities'])->toContain('campaign.fanout');
});

it('requires reconciliation for ambiguous dispatch outcomes instead of blind duplicate retries', function () {
    $matrix = task0017ReferenceProviderMatrix();

    foreach ($matrix as $provider => $contract) {
        expect($contract['send']['provider_native_idempotency_proven'], $provider)->toBeFalse()
            ->and($contract['send']['ambiguous_outcome_requires_reconciliation'], $provider)->toBeTrue();
    }
});

it('keeps webhook authenticity connector-owned and advertises only executable verifier strategies', function () {
    $matrix = task0017ReferenceProviderMatrix();

    expect($matrix['brevo']['webhook']['advertised'])->toBeTrue()
        ->and($matrix['brevo']['webhook']['verifier_strategies'])->toBe([
            'source_ip',
            'basic_auth',
            'bearer',
            'configured_header',
        ])
        ->and($matrix['amazon-ses']['webhook']['advertised'])->toBeFalse()
        ->and($matrix['amazon-ses']['webhook']['verifier_strategies'])->toBe([])
        ->and($matrix['gmail-api']['webhook']['advertised'])->toBeFalse()
        ->and($matrix['gmail-api']['webhook']['verifier_strategies'])->toBe([]);

    foreach ($matrix as $provider => $contract) {
        expect($contract['webhook']['verifier_strategies'], $provider)->not->toContain('universal_hmac');
    }
});

it('does not pull routing failover or social implementation into TASK-0017', function () {
    $matrix = task0017ReferenceProviderMatrix();

    foreach ($matrix as $provider => $contract) {
        expect($contract['unsupported_phase_capabilities'], $provider)
            ->toContain('routing.failover')
            ->toContain('social.publish');
    }
});
