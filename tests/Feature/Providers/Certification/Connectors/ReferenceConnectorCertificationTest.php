<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Infrastructure\Connectors\AmazonSes\AmazonSesConnector;
use App\Modules\Providers\Infrastructure\Connectors\Brevo\BrevoConnector;
use App\Modules\Providers\Infrastructure\Connectors\Gmail\GmailConnector;

it('certifies reference connector manifests stay aligned with accepted TASK-0017 contract evidence', function () {
    /** @var array<string, array<string, mixed>> $matrix */
    $matrix = require base_path('tests/Fixtures/Providers/ConnectorMatrix/reference-provider-matrix.php');
    $observedAt = new \DateTimeImmutable('2026-09-04T00:00:00+00:00');
    $manifests = [
        'amazon-ses' => (new AmazonSesConnector($observedAt))->manifest(),
        'brevo' => (new BrevoConnector($observedAt))->manifest(),
        'gmail-api' => (new GmailConnector($observedAt))->manifest(),
    ];

    expect(array_keys($manifests))->toBe(array_keys($matrix));

    foreach ($manifests as $key => $manifest) {
        $evidence = $matrix[$key];
        $send = $manifest->capability('email.send');

        expect($manifest->connectorKey)->toBe($key)
            ->and($manifest->metadata['provider_class'] ?? null)->toBe($evidence['connector_class'])
            ->and($manifest->metadata['credentials'] ?? null)->toBe('secret-reference-only')
            ->and($manifest->metadata['acceptance_is_delivery'] ?? null)->toBeFalse()
            ->and($send->support)->toBe(CapabilitySupport::Supported)
            ->and($send->constraints['provider_idempotency_token'] ?? null)->toBeFalse()
            ->and($evidence['send']['provider_native_idempotency_proven'])->toBeFalse()
            ->and($evidence['send']['ambiguous_outcome_requires_reconciliation'])->toBeTrue();

        foreach ($evidence['unsupported_phase_capabilities'] as $operation) {
            expect($manifest->capability($operation)->support)
                ->toBe(CapabilitySupport::Unknown);
        }
    }

    expect($manifests['amazon-ses']->capability('quota.read')->support)->toBe(CapabilitySupport::Supported)
        ->and($manifests['brevo']->capability('quota.observe')->support)->toBe(CapabilitySupport::Supported)
        ->and($manifests['gmail-api']->capability('quota.observe')->support)->toBe(CapabilitySupport::Supported);
});

it('certifies webhook and mailbox semantics use executable runtime strategies without weakening historical evidence', function () {
    /** @var array<string, array<string, mixed>> $matrix */
    $matrix = require base_path('tests/Fixtures/Providers/ConnectorMatrix/reference-provider-matrix.php');
    $observedAt = new \DateTimeImmutable('2026-09-04T00:00:00+00:00');
    $ses = (new AmazonSesConnector($observedAt))->manifest();
    $brevo = (new BrevoConnector($observedAt))->manifest();
    $gmail = (new GmailConnector($observedAt))->manifest();

    $evidenceToRuntime = [
        'source_ip' => 'source_ip',
        'basic_auth' => 'basic',
        'bearer' => 'bearer',
        'configured_header' => 'custom_headers',
    ];
    $expectedBrevoStrategies = array_map(
        fn (string $strategy): string => $evidenceToRuntime[$strategy],
        $matrix['brevo']['webhook']['verifier_strategies'],
    );

    expect($brevo->capability('webhook.verify')->support)->toBe(CapabilitySupport::Supported)
        ->and($brevo->capability('webhook.verify')->constraints['strategies'] ?? null)->toBe($expectedBrevoStrategies)
        ->and($brevo->capability('webhook.verify')->constraints['universal_hmac_assumed'] ?? null)->toBeFalse()
        ->and($ses->capability('webhook.verify')->support)->toBe(CapabilitySupport::Unsupported)
        ->and($gmail->capability('webhook.verify')->support)->toBe(CapabilitySupport::Unsupported)
        ->and($gmail->metadata['provider_class'] ?? null)->toBe('mailbox')
        ->and($gmail->metadata['bulk_marketing_provider'] ?? null)->toBeFalse()
        ->and($gmail->capability('email.send')->constraints['bulk_marketing'] ?? null)->toBeFalse()
        ->and($gmail->capability('email.send')->constraints['required_scope'] ?? null)->toBe(GmailConnector::SEND_SCOPE)
        ->and($matrix['gmail-api']['send']['mailbox_only'])->toBeTrue()
        ->and($matrix['gmail-api']['send']['bulk_marketing_semantics'])->toBeFalse();
});
