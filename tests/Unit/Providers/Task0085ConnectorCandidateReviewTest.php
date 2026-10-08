<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidateApprovalVerifier;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidateGenerator;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidatePromotionGate;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidateReviewService;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorDescriptionIngestor;
use App\Modules\Providers\Application\ConnectorFactory\OpenApiConnectorPlanner;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateApproval;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateCanaryPolicy;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateSandboxPolicy;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorGeneratedCandidate;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0085ConnectorCandidateReviewTest extends TestCase
{
    public function test_review_is_deterministic_immutable_and_never_grants_activation(): void
    {
        $candidate = (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0');
        $reviewer = new ConnectorCandidateReviewService;

        $first = $reviewer->review($candidate);
        $second = $reviewer->review($candidate);

        self::assertTrue($first->passed);
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame(64, strlen($first->evidenceSha256));
        self::assertFalse($first->activationAllowed);
        self::assertSame('candidate_only', $first->activationState);
        self::assertSame([
            'static_analysis', 'dependency_review', 'contract_review', 'sandbox_policy', 'adversarial_review',
        ], array_keys($first->checks));
        self::assertSame([], $first->checks['sandbox_policy']['findings']);
        self::assertFalse($first->checks['sandbox_policy']['details']['sandbox_policy']['execution_performed']);
        self::assertSame('denied', $first->checks['sandbox_policy']['details']['sandbox_policy']['network_access']);
        self::assertSame($first->artifacts, array_map(
            static fn (array $artifact): array => ['path' => $artifact['path'], 'sha256' => $artifact['sha256']],
            $first->checks['contract_review']['details']['artifact_hashes'],
        ));
    }

    public function test_review_fails_closed_on_unsafe_generated_php_and_binds_failure_to_artifact_hash(): void
    {
        $candidate = (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0');
        $values = $candidate->toArray();
        $files = $values['files'];
        $files[1]['content'] .= "\n\$payload = file_put_contents('/tmp/payload', 'unexpected');\n";
        $files[1]['sha256'] = hash('sha256', $files[1]['content']);
        $mutated = new ConnectorGeneratedCandidate(
            workspaceId: $values['workspace_id'],
            providerKey: $values['provider_key'],
            candidateId: $values['candidate_id'],
            generatorVersion: $values['generator_version'],
            templateVersion: $values['template_version'],
            toolchainVersion: $values['toolchain_version'],
            inputPlanSha256: $values['input_plan_sha256'],
            files: $files,
        );

        $evidence = (new ConnectorCandidateReviewService)->review($mutated);

        self::assertFalse($evidence->passed);
        self::assertSame('failed', $evidence->checks['static_analysis']['status']);
        self::assertContains('generated_php_differs_from_pinned_template', $evidence->checks['static_analysis']['findings']);
        self::assertSame($files[1]['sha256'], $evidence->artifacts[1]['sha256']);
        self::assertFalse($evidence->activationAllowed);
    }

    public function test_validation_evidence_rejects_changed_details_without_a_matching_digest(): void
    {
        $evidence = (new ConnectorCandidateReviewService)->review(
            (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0'),
        );
        $values = $evidence->toArray();
        $checks = $values['checks'];
        $checks['static_analysis']['details']['reviewer_version'] = 'forged-reviewer';

        $this->expectException(InvalidArgumentException::class);
        new \App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateValidationEvidence(
            candidateId: $values['candidate_id'],
            workspaceId: $values['workspace_id'],
            providerKey: $values['provider_key'],
            inputPlanSha256: $values['input_plan_sha256'],
            artifacts: $values['artifacts'],
            checks: $checks,
            passed: $values['passed'],
        );
    }

    public function test_promotion_requires_independent_exact_evidence_approval_and_enabled_bounded_canary(): void
    {
        $evidence = (new ConnectorCandidateReviewService)->review(
            (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0'),
        );
        $gate = new ConnectorCandidatePromotionGate;
        $verifier = new class implements ConnectorCandidateApprovalVerifier
        {
            public function isAuthorized(ConnectorCandidateApproval $approval): bool
            {
                return $approval->approverId === 'trusted-reviewer';
            }
        };
        $approval = new ConnectorCandidateApproval(
            candidateId: $evidence->candidateId,
            evidenceSha256: $evidence->evidenceSha256,
            approvalId: hash('sha256', 'approval-1'),
            approverId: 'trusted-reviewer',
            approvedAt: new \DateTimeImmutable('2026-10-08T00:00:00+00:00'),
        );

        $deniedByDefault = $gate->decide(
            $evidence,
            $approval,
            new ConnectorCandidateCanaryPolicy,
            $verifier,
        );
        self::assertFalse($deniedByDefault->allowed);
        self::assertSame('candidate_only', $deniedByDefault->state);
        self::assertSame('canary_disabled', $deniedByDefault->reason);

        $enabled = $gate->decide(
            $evidence,
            $approval,
            new ConnectorCandidateCanaryPolicy(true, 5, 10, 1440, true),
            $verifier,
        );
        self::assertTrue($enabled->allowed);
        self::assertSame('approved_for_canary', $enabled->state);
        self::assertSame($evidence->evidenceSha256, $enabled->evidenceSha256);
    }

    public function test_missing_mismatched_or_untrusted_approval_denies_promotion(): void
    {
        $evidence = (new ConnectorCandidateReviewService)->review(
            (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0'),
        );
        $policy = new ConnectorCandidateCanaryPolicy(true, 2, 3, 60, true);
        $gate = new ConnectorCandidatePromotionGate;
        $untrusted = new class implements ConnectorCandidateApprovalVerifier
        {
            public function isAuthorized(ConnectorCandidateApproval $approval): bool
            {
                return false;
            }
        };

        self::assertSame('independent_authorized_approval_required', $gate->decide($evidence, null, $policy, $untrusted)->reason);

        $mismatched = new ConnectorCandidateApproval(
            candidateId: hash('sha256', 'other-candidate'),
            evidenceSha256: $evidence->evidenceSha256,
            approvalId: hash('sha256', 'approval-2'),
            approverId: 'trusted-reviewer',
            approvedAt: new \DateTimeImmutable('2026-10-08T00:00:00+00:00'),
        );
        self::assertSame('independent_authorized_approval_required', $gate->decide($evidence, $mismatched, $policy, $untrusted)->reason);
    }

    public function test_canary_policy_rejects_unbounded_or_irreversible_values(): void
    {
        try {
            new ConnectorCandidateCanaryPolicy(true, 6, 10, 60, true);
            self::fail('Exposure above 5 percent was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            new ConnectorCandidateCanaryPolicy(true, 1, 1, 60, false);
            self::fail('An irreversible canary was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function test_sandbox_policy_rejects_network_secrets_and_unbounded_resources(): void
    {
        try {
            new ConnectorCandidateSandboxPolicy(networkAccess: 'enabled');
            self::fail('Network access in the sandbox policy was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            new ConnectorCandidateSandboxPolicy(memoryMib: 513);
            self::fail('An unbounded sandbox memory limit was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function plan(): ConnectorPlanCandidate
    {
        $source = json_encode([
            'openapi' => '3.2.1',
            'info' => ['title' => 'Example API', 'version' => '1'],
            'components' => ['securitySchemes' => [
                'ExampleKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ]],
            'paths' => ['/contacts' => ['get' => ['operationId' => 'listContacts']]],
        ], JSON_THROW_ON_ERROR);

        $description = (new ConnectorDescriptionIngestor)->ingest(
            workspaceId: 'workspace-a',
            sourceUri: 'https://docs.example.test/openapi.json',
            mediaType: 'application/json',
            contents: $source,
            retrievedAt: '2026-10-08T00:00:00+00:00',
        );

        return (new OpenApiConnectorPlanner)->plan($description, 'example');
    }
}
