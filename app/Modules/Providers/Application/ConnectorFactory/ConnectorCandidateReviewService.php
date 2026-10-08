<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateValidationEvidence;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorGeneratedCandidate;
use JsonException;

final class ConnectorCandidateReviewService
{
    public const REVIEWER_VERSION = 'candidate-review-v1';

    private const SANDBOX_POLICY = [
        'name' => 'connector-candidate-sandbox-v1',
        'execution_performed' => false,
        'network_access' => 'denied',
        'secret_mounts' => false,
        'provider_authority' => false,
        'input_filesystem' => 'read_only',
        'no_new_privileges' => true,
        'seccomp' => 'default',
        'cpu_millis' => 2500,
        'memory_mib' => 256,
        'process_limit' => 32,
        'timeout_seconds' => 30,
    ];

    /**
     * Inspect candidate bytes as data only. No generated candidate code is executed.
     *
     * @throws JsonException
     */
    public function review(ConnectorGeneratedCandidate $candidate): ConnectorCandidateValidationEvidence
    {
        $files = [];
        foreach ($candidate->files as $file) {
            $files[$file['path']] = $file['content'];
        }

        $className = $this->className($candidate->providerKey);
        $root = 'connector-candidates/'.$candidate->providerKey.'/';
        $manifestPath = $root.'candidate.json';
        $adapterPath = $root.'src/'.$className.'ConnectorCandidate.php';
        $testPath = $root.'tests/'.$className.'ConnectorCandidateTest.php';
        $manifestContent = $files[$manifestPath] ?? '';
        $adapterContent = $files[$adapterPath] ?? '';
        $testContent = $files[$testPath] ?? '';
        $manifest = json_decode($manifestContent, true);

        $pathFindings = $this->pathFindings(array_keys($files), [$manifestPath, $adapterPath, $testPath]);
        $retrievedAt = is_array($manifest) ? ($manifest['source_provenance']['retrieved_at'] ?? '') : '';
        $retrievedAt = is_string($retrievedAt) ? $retrievedAt : '';
        $staticFindings = $this->staticFindings([
            ['path' => $adapterPath, 'content' => $adapterContent],
            ['path' => $testPath, 'content' => $testContent],
        ], $testPath, $className, $candidate->providerKey, $retrievedAt);
        $dependencyFindings = $this->dependencyFindings($files, is_array($manifest) ? $manifest : []);
        $contractFindings = $this->contractFindings($candidate, is_array($manifest) ? $manifest : [], $adapterContent, $testContent);
        $sandboxFindings = $this->sandboxFindings();
        $adversarialFindings = $this->adversarialFindings($files, $pathFindings);

        $findings = [
            'static_analysis' => $staticFindings,
            'dependency_review' => $dependencyFindings,
            'contract_review' => $contractFindings,
            'sandbox_policy' => $sandboxFindings,
            'adversarial_review' => $adversarialFindings,
        ];

        $checks = [];
        foreach ($findings as $name => $items) {
            $observed = [
                'reviewer_version' => self::REVIEWER_VERSION,
                'candidate_id' => $candidate->candidateId,
                'input_plan_sha256' => $candidate->inputPlanSha256,
                'generator_version' => $candidate->generatorVersion,
                'template_version' => $candidate->templateVersion,
                'toolchain_version' => $candidate->toolchainVersion,
                'review_runtime' => PHP_VERSION,
                'dependency_manifest' => $name === 'dependency_review' ? ($manifest['dependencies'] ?? null) : null,
                'artifact_hashes' => array_map(
                    static fn (array $file): array => ['path' => $file['path'], 'sha256' => $file['sha256']],
                    $candidate->files,
                ),
                'check' => $name,
                'findings' => $items,
                'sandbox_policy' => $name === 'sandbox_policy' ? self::SANDBOX_POLICY : null,
            ];
            $checks[$name] = [
                'status' => $items === [] ? 'passed' : 'failed',
                'evidence_sha256' => hash('sha256', $this->canonicalJson($observed)),
                'findings' => $items,
                'details' => $observed,
            ];
        }

        $passed = array_reduce(
            $checks,
            static fn (bool $result, array $check): bool => $result && $check['status'] === 'passed',
            true,
        );

        $artifacts = array_map(
            static fn (array $file): array => ['path' => $file['path'], 'sha256' => $file['sha256']],
            $candidate->files,
        );

        return new ConnectorCandidateValidationEvidence(
            candidateId: $candidate->candidateId,
            workspaceId: $candidate->workspaceId,
            providerKey: $candidate->providerKey,
            inputPlanSha256: $candidate->inputPlanSha256,
            artifacts: $artifacts,
            checks: $checks,
            passed: $passed,
        );
    }

    /** @param list<string> $paths @param list<string> $expected @return list<string> */
    private function pathFindings(array $paths, array $expected): array
    {
        sort($paths, SORT_STRING);
        sort($expected, SORT_STRING);

        return $paths === $expected ? [] : ['artifact_path_set_outside_fixed_allowlist'];
    }

    /**
     * @param list<array{path: string, content: string}> $sources
     * @return list<string>
     */
    private function staticFindings(array $sources, string $testPath, string $className, string $providerKey, string $retrievedAt): array
    {
        $findings = [];
        $expectedSources = [
            'connector-candidates/'.$providerKey.'/src/'.$className.'ConnectorCandidate.php' => $this->expectedAdapterSource($className, $providerKey, $retrievedAt),
            $testPath => $this->expectedTestSource($className, $providerKey),
        ];
        $safeInclude = "require_once __DIR__.'/../src/{$className}ConnectorCandidate.php';";

        foreach ($sources as $source) {
            if (($expectedSources[$source['path']] ?? null) !== $source['content']) {
                $findings[] = 'generated_php_differs_from_pinned_template';
            }

            try {
                $tokens = token_get_all($source['content'], TOKEN_PARSE);
            } catch (\ParseError) {
                $findings[] = 'generated_php_has_syntax_error';
                continue;
            }

            foreach ($tokens as $token) {
                if (is_array($token) === false) {
                    continue;
                }

                if (in_array($token[0], [T_EVAL, T_EXIT, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE], true)) {
                    $findings[] = 'generated_php_contains_dynamic_execution_or_include';
                }

                if ($token[0] === T_REQUIRE_ONCE
                    && ($source['path'] !== $testPath
                        || str_contains($source['content'], $safeInclude) === false
                        || substr_count($source['content'], 'require_once') !== 1)) {
                    $findings[] = 'generated_test_include_is_not_the_fixed_sibling_adapter';
                }

                if ($token[0] === T_STRING && in_array(strtolower($token[1]), [
                    'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'curl_exec', 'curl_multi_exec',
                ], true)) {
                    $findings[] = 'generated_php_contains_process_or_network_execution';
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /** @param array<string, string> $files @param array<string, mixed> $manifest @return list<string> */
    private function dependencyFindings(array $files, array $manifest): array
    {
        $findings = [];
        if (($manifest['dependencies'] ?? null) !== []) {
            $findings[] = 'candidate_declares_unreviewed_dependencies';
        }

        foreach (array_keys($files) as $path) {
            if (preg_match('/(?:^|\/)(?:composer\.(?:json|lock)|package(?:-lock)?\.json|\.github\/workflows\/)/i', $path) === 1) {
                $findings[] = 'candidate_contains_dependency_or_workflow_manifest';
            }
        }

        return array_values(array_unique($findings));
    }

    /** @param array<string, mixed> $manifest @return list<string> */
    private function contractFindings(
        ConnectorGeneratedCandidate $candidate,
        array $manifest,
        string $adapter,
        string $test,
    ): array {
        $findings = [];
        if (($manifest['candidate_id'] ?? null) !== $candidate->candidateId
            || ($manifest['input_plan_sha256'] ?? null) !== $candidate->inputPlanSha256
            || ($manifest['workspace_id'] ?? null) !== $candidate->workspaceId
            || ($manifest['provider_key'] ?? null) !== $candidate->providerKey) {
            $findings[] = 'manifest_identity_does_not_match_candidate';
        }

        if (($manifest['status'] ?? null) !== 'candidate_only'
            || ($manifest['executable'] ?? true) !== false
            || ($manifest['authority_granted'] ?? true) !== false
            || ($manifest['activation']['allowed'] ?? true) !== false) {
            $findings[] = 'manifest_does_not_preserve_inert_candidate_state';
        }

        foreach (['ConnectorAdapter', 'capabilities: []', "'candidate_only'", "'no_provider_io'", "'no_credentials'", "'activation_allowed' => false"] as $required) {
            if (str_contains($adapter, $required) === false) {
                $findings[] = 'adapter_contract_missing:'.str_replace(' ', '_', $required);
            }
        }

        if (str_contains($test, 'testGeneratedAdapterRemainsAnInertCandidate') === false
            || str_contains($test, "'no_provider_io'") === false
            || str_contains($test, "'no_credentials'") === false) {
            $findings[] = 'generated_contract_test_is_incomplete';
        }

        return array_values(array_unique($findings));
    }

    /** @return list<string> */
    private function sandboxFindings(): array
    {
        $policy = self::SANDBOX_POLICY;
        if ($policy['execution_performed'] !== false
            || $policy['network_access'] !== 'denied'
            || $policy['secret_mounts'] !== false
            || $policy['provider_authority'] !== false
            || $policy['input_filesystem'] !== 'read_only'
            || $policy['no_new_privileges'] !== true
            || $policy['seccomp'] !== 'default'
            || $policy['cpu_millis'] > 5000
            || $policy['memory_mib'] > 512
            || $policy['process_limit'] > 64
            || $policy['timeout_seconds'] > 60) {
            return ['candidate_sandbox_policy_exceeds_declared_bounds'];
        }

        return [];
    }

    /** @param array<string, string> $files @param list<string> $pathFindings @return list<string> */
    private function adversarialFindings(array $files, array $pathFindings): array
    {
        $findings = $pathFindings;
        foreach ($files as $content) {
            if (preg_match('/\b(?:system|exec|shell_exec|passthru|proc_open|popen|curl_exec)\s*\(/i', $content) === 1) {
                $findings[] = 'runtime_injection_pattern_present';
            }

            if (str_contains($content, '.github/workflows') || str_contains($content, 'composer require')) {
                $findings[] = 'workflow_or_dependency_injection_payload_present';
            }
        }

        return array_values(array_unique($findings));
    }

    private function expectedAdapterSource(string $className, string $providerKey, string $timestamp): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Generated\\ConnectorCandidates;\n\nuse App\\Modules\\Providers\\Domain\\Connectors\\ConnectorManifest;\nuse App\\Modules\\Providers\\Domain\\Connectors\\Contracts\\ConnectorAdapter;\nuse DateTimeImmutable;\n\nfinal readonly class {$className}ConnectorCandidate implements ConnectorAdapter\n{\n    public function manifest(): ConnectorManifest\n    {\n        return new ConnectorManifest(\n            connectorKey: ".var_export('candidate-'.$providerKey, true).",\n            connectorVersion: '0.0.0-candidate',\n            apiVersionStrategy: 'manual-review-required',\n            documentationUrl: '',\n            observedAt: new DateTimeImmutable(".var_export($timestamp, true)."),\n            sandboxLimitations: ['candidate_only', 'no_provider_io', 'no_credentials'],\n            capabilities: [],\n            metadata: ['activation_allowed' => false],\n        );\n    }\n}\n";
    }

    private function expectedTestSource(string $className, string $providerKey): string
    {
        return "<?php\n\nnamespace Tests\\Generated\\ConnectorCandidates;\n\nuse Generated\\ConnectorCandidates\\{$className}ConnectorCandidate;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$className}ConnectorCandidateTest extends TestCase\n{\n    public function testGeneratedAdapterRemainsAnInertCandidate(): void\n    {\n        require_once __DIR__.'/../src/{$className}ConnectorCandidate.php';\n\n        \$manifest = (new {$className}ConnectorCandidate)->manifest();\n\n        self::assertSame('candidate-{$providerKey}', \$manifest->connectorKey);\n        self::assertSame('0.0.0-candidate', \$manifest->connectorVersion);\n        self::assertSame([], \$manifest->capabilities);\n        self::assertContains('candidate_only', \$manifest->sandboxLimitations);\n        self::assertContains('no_provider_io', \$manifest->sandboxLimitations);\n        self::assertContains('no_credentials', \$manifest->sandboxLimitations);\n        self::assertFalse(\$manifest->metadata['activation_allowed']);\n    }\n}\n";
    }

    private function className(string $providerKey): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $providerKey)));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $child): mixed => $this->canonicalize($child), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
