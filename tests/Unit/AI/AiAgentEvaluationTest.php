<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\AiAgentCatalog;
use App\Modules\AI\Domain\AiEvaluationReport;
use App\Modules\AI\Domain\AiPromptPromotionGate;
use App\Modules\AI\Domain\Contracts\AiPromptPromotionAuthority;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiAgentEvaluationTest extends TestCase
{
    private function catalog(): AiAgentCatalog
    {
        return new AiAgentCatalog(dirname(__DIR__, 3));
    }

    private function samples(array $definition): array
    {
        $samples = [];
        foreach ($definition['dataset']['cases'] as $case) {
            $samples[$case['id']] = ['status' => 'complete', 'schema_id' => $definition['schema_id'], 'output' => $case['output']];
        }

        return $samples;
    }

    public function test_all_specialists_have_pinned_positive_and_negative_golden_policy_cases(): void
    {
        $agents = json_decode(file_get_contents(dirname(__DIR__, 3).'/.ai/ai/AGENT-REGISTRY.yaml'), true, 32, JSON_THROW_ON_ERROR)['agents'];
        foreach ($agents as $agent) {
            $definition = $this->catalog()->resolve($agent['id'], 'v1');
            $report = AiEvaluationReport::evaluate($definition, $this->samples($definition));
            self::assertTrue($report->passed, $agent['id']);
            self::assertCount(4, $report->outcomes);
            self::assertSame('offline_contract', $report->toArray()['evidence_kind']);
            self::assertSame($report->hash(), AiEvaluationReport::evaluate($definition, $this->samples($definition))->hash());
        }
    }

    public function test_missing_duplicate_extra_and_failed_cases_cannot_pass(): void
    {
        $definition = $this->catalog()->resolve('strategy', 'v1');
        $samples = $this->samples($definition);
        unset($samples['invented-reference']);
        self::assertFalse(AiEvaluationReport::evaluate($definition, $samples)->passed);
        $samples = $this->samples($definition);
        $samples['unexpected-case'] = $samples['grounded-proposal'];
        self::assertFalse(AiEvaluationReport::evaluate($definition, $samples)->passed);
        $samples = $this->samples($definition);
        $samples['grounded-proposal']['output']['tool_ids'] = ['publish'];
        self::assertFalse(AiEvaluationReport::evaluate($definition, $samples)->passed);
        $definition['dataset']['cases'][] = $definition['dataset']['cases'][0];
        self::assertFalse(AiEvaluationReport::evaluate($definition, $samples)->passed);
    }

    public function test_unknown_expectation_cannot_disguise_a_rejected_sample_as_a_pass(): void
    {
        $definition = $this->catalog()->resolve('strategy', 'v1');
        $samples = $this->samples($definition);
        $definition['dataset']['cases'][2]['expected'] = 'model_says_pass';
        self::assertFalse(AiEvaluationReport::evaluate($definition, $samples)->passed);
    }

    public function test_promotion_and_rollback_need_exact_report_and_independent_review_and_never_enable_live_routes(): void
    {
        $definition = $this->catalog()->resolve('strategy', 'v1');
        $report = AiEvaluationReport::evaluate($definition, $this->samples($definition));
        $authority = new class implements AiPromptPromotionAuthority
        {
            public ?string $actor = 'human-reviewer';

            public function reviewer(TenantContext $scope, string $candidateHash, string $reportHash): ?string
            {
                return $this->actor;
            }
        };
        $gate = new AiPromptPromotionGate($authority);
        $scope = new TenantContext('org-a', 'workspace-a', null, 'release-operator');
        self::assertSame('authorized_offline', $gate->authorize($definition, $report, $scope, 'candidate-author', 'offline')['status']);
        foreach ([null, 'candidate-author', 'release-operator', 'strategy'] as $actor) {
            $authority->actor = $actor;
            try {
                $gate->authorize($definition, $report, $scope, 'candidate-author', 'offline');
                self::fail('Self/missing review was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $authority->actor = 'human-reviewer';
        foreach (['live', 'production'] as $mode) {
            try {
                $gate->authorize($definition, $report, $scope, 'candidate-author', $mode);
                self::fail('Offline evidence enabled live promotion.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $definition['prompt_sha256'] = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        $gate->authorize($definition, $report, $scope, 'candidate-author', 'offline');
    }

    public function test_unknown_agents_and_mutable_or_unregistered_versions_deny(): void
    {
        foreach ([['unknown', 'v1'], ['strategy', 'latest'], ['strategy', 'v999']] as [$agent, $version]) {
            try {
                $this->catalog()->resolve($agent, $version);
                self::fail('Unknown agent/version accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
