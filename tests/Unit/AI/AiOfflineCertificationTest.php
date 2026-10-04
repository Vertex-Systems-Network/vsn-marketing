<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\AiAgentCatalog;
use App\Modules\AI\Domain\AiEvaluationReport;
use App\Modules\AI\Domain\AiPromptPromotionGate;
use App\Modules\AI\Domain\Contracts\AiPromptPromotionAuthority;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\AiRuntimeHarness;

final class AiOfflineCertificationTest extends TestCase
{
    public function test_canary_and_rollback_select_exact_evaluated_versions_with_current_independent_review(): void
    {
        $catalog = new AiAgentCatalog(dirname(__DIR__, 3));
        $authority = new class implements AiPromptPromotionAuthority
        {
            public array $requests = [];

            public array $grants = [];

            public function reviewer(TenantContext $scope, string $candidateHash, string $reportHash): ?string
            {
                $this->requests[] = [$candidateHash, $reportHash];

                return ($this->grants[$candidateHash] ?? null) === $reportHash ? 'independent-human-fixture' : null;
            }
        };
        $gate = new AiPromptPromotionGate($authority);
        $scope = new TenantContext('org-a', 'workspace-a', 'brand-a', 'release-operator');
        $receipts = [];
        foreach (['v2', 'v1'] as $version) {
            $definition = $catalog->resolve('strategy', $version);
            $samples = [];
            foreach ($definition['dataset']['cases'] as $case) {
                $samples[$case['id']] = ['status' => 'complete', 'schema_id' => $definition['schema_id'], 'output' => $case['output']];
            }
            $report = AiEvaluationReport::evaluate($definition, $samples);
            self::assertTrue($report->passed);
            try {
                $gate->authorize($definition, $report, $scope, 'candidate-author', 'offline');
                self::fail('Missing review accepted.');
            } catch (InvalidArgumentException) {
                [$candidate, $reportHash] = end($authority->requests);
                $authority->grants[$candidate] = $reportHash;
            }
            $receipt = $gate->authorize($definition, $report, $scope, 'candidate-author', 'offline');
            self::assertSame('authorized_offline', $receipt['status']);
            self::assertSame($version, $receipt['prompt_version']);
            $receipts[] = $receipt;
            foreach (['live', 'production'] as $mode) {
                try {
                    $gate->authorize($definition, $report, $scope, 'candidate-author', $mode);
                    self::fail('Offline evidence promoted production.');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
            try {
                $gate->authorize($definition, $report, new TenantContext('org-b', 'workspace-b', 'brand-b', 'release-operator'), 'candidate-author', 'offline');
                self::fail('Review transferred to another workspace.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
            [$runtime, $adapter] = AiRuntimeHarness::fixture();
            $plan = [array_replace(AiRuntimeHarness::step(), ['prompt_version' => $version])];
            $result = $runtime->run(AiRuntimeHarness::scope(), 'offline-rehearsal-'.$version, $plan, new DateTimeImmutable('2026-10-01'));
            self::assertSame('completed', $result['status']);
            self::assertSame($version, $result['steps'][0]['prompt_version']);
            self::assertSame($definition['prompt_sha256'], $result['steps'][0]['prompt_sha256']);
            self::assertCount(1, $adapter->calls);
        }
        self::assertNotSame($receipts[0]['candidate_sha256'], $receipts[1]['candidate_sha256']);
        $registry = json_decode(file_get_contents(dirname(__DIR__, 3).'/.ai/ai/PROMPT-REGISTRY.yaml'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($registry['prompts'] as $prompt) {
            self::assertNull($prompt['current_version']);
            self::assertSame('candidate', $prompt['status']);
        }
    }

    public function test_committed_offline_measurement_is_complete_bound_to_source_and_has_no_production_claim(): void
    {
        $root = dirname(__DIR__, 3);
        $evidence = json_decode(file_get_contents($root.'/.ai/research/PHASE-10/OFFLINE-MEASUREMENTS.v1.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('offline_contract', $evidence['evidence_kind']);
        self::assertFalse($evidence['production_slo_claim']);
        self::assertSame('synthetic_fixture_units_not_currency', $evidence['cost_unit']);
        self::assertCount(40, $evidence['samples']);
        foreach ($evidence['source_hashes'] as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', $root.'/'.$path), $path);
        }
        foreach ($evidence['samples'] as $index => $sample) {
            self::assertSame($index, $sample['sample_index']);
            self::assertSame($index < 20 ? 'v1' : 'v2', $sample['version']);
            self::assertSame('completed', $sample['status']);
            self::assertSame(1, $sample['provider_calls']);
            self::assertSame([10], $sample['reserved_fixture_units']);
            self::assertSame([2], $sample['settled_fixture_units']);
            self::assertSame(['input_tokens' => 3, 'output_tokens' => 4], $sample['usage']);
            self::assertGreaterThanOrEqual(0, $sample['elapsed_ms']);
        }
        $times = array_column($evidence['samples'], 'elapsed_ms');
        sort($times);
        self::assertSame($times[19], $evidence['summary']['p50_ms_nearest_rank']);
        self::assertSame($times[37], $evidence['summary']['p95_ms_nearest_rank']);
        self::assertSame(80, $evidence['summary']['settled_fixture_units']);
    }
}
