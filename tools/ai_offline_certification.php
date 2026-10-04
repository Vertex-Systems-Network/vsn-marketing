<?php

/** Reproducible local fixture capture. No network, credentials, real model or production SLO. */
require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\AI\AiRuntimeHarness;

$root = dirname(__DIR__);
$samples = [];
foreach (['v1', 'v2'] as $version) {
    // One unrecorded warm-up per version; each sample then uses fresh synthetic ports.
    [$warm] = AiRuntimeHarness::fixture();
    $plan = [array_replace(AiRuntimeHarness::step(), ['prompt_version' => $version])];
    $warm->run(AiRuntimeHarness::scope(), 'warm-'.$version, $plan, new DateTimeImmutable('2026-10-01'));
    for ($iteration = 0; $iteration < 20; $iteration++) {
        [$runtime, $adapter, , $ledger] = AiRuntimeHarness::fixture();
        $index = count($samples);
        $started = hrtime(true);
        $result = $runtime->run(AiRuntimeHarness::scope(), 'measure-'.$index, $plan, new DateTimeImmutable('2026-10-01'));
        $elapsed = (hrtime(true) - $started) / 1000000;
        if ($result['status'] !== 'completed' || count($adapter->calls) !== 1 || $ledger->settled !== [2]) {
            throw new RuntimeException('Offline measurement outcome rejected.');
        }
        $step = $result['steps'][0];
        $samples[] = ['sample_index' => $index, 'version' => $version, 'status' => $result['status'],
            'elapsed_ms' => $elapsed, 'gateway_latency_ms' => $step['latency_ms'], 'provider_calls' => count($adapter->calls),
            'prompt_sha256' => $step['prompt_sha256'], 'context_manifest_sha256' => $step['context_manifest_sha256'],
            'reserved_fixture_units' => $ledger->reserved, 'settled_fixture_units' => $ledger->settled,
            'usage' => $step['result']['usage']];
    }
}
$paths = array_merge(glob($root.'/app/Modules/AI/Application/*.php'), glob($root.'/app/Modules/AI/Domain/*.php'),
    glob($root.'/app/Modules/AI/Domain/Contracts/*.php'), glob($root.'/.ai/ai/*.yaml'),
    glob($root.'/resources/ai/prompts/*.json'), glob($root.'/resources/ai/evals/*.json'),
    [$root.'/tests/Support/AI/AiRuntimeHarness.php', __FILE__, $root.'/composer.lock']);
sort($paths);
$hashes = [];
foreach ($paths as $path) {
    $hashes[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
}
$times = array_column($samples, 'elapsed_ms');
sort($times);
$report = ['schema_version' => 1, 'evidence_kind' => 'offline_contract', 'captured_at' => gmdate('c'),
    'php_version' => PHP_VERSION, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'command' => 'php tools/ai_offline_certification.php',
    'measured_scope' => 'Warm process, fresh synthetic ports per sample, one-step context/catalog/runtime/gateway invocation; excludes harness construction and warm-up. No external network/model.',
    'production_slo_claim' => false, 'cost_unit' => 'synthetic_fixture_units_not_currency',
    'source_hashes' => $hashes, 'samples' => $samples,
    'summary' => ['sample_count' => 40, 'p50_ms_nearest_rank' => $times[19], 'p95_ms_nearest_rank' => $times[37],
        'settled_fixture_units' => array_sum(array_map(fn (array $sample): int => array_sum($sample['settled_fixture_units']), $samples))]];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
