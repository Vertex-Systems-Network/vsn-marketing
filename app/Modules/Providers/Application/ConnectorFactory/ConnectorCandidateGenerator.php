<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorGeneratedCandidate;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class ConnectorCandidateGenerator
{
    public const GENERATOR_VERSION = 'task0084-v1';

    public const TEMPLATE_VERSION = 'connector-shell-v1';

    /**
     * Build inert candidate artifacts as strings. This class deliberately has no filesystem,
     * process, network, package-manager, credential, or activation interface.
     *
     * @throws JsonException
     */
    public function generate(ConnectorPlanCandidate $plan, string $toolchainVersion): ConnectorGeneratedCandidate
    {
        if ($plan->executable || $plan->authorityGranted || $plan->activationState !== 'candidate_only') {
            throw new InvalidArgumentException('Only non-executable, authority-free connector plans can be generated.');
        }

        if ($plan->workspaceId !== $plan->provenance->workspaceId) {
            throw new InvalidArgumentException('Connector plan workspace does not match its source provenance.');
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $plan->providerKey) !== 1) {
            throw new InvalidArgumentException('Provider key must be a lowercase, letter-leading slug before candidate generation.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/D', $toolchainVersion) !== 1) {
            throw new InvalidArgumentException('Toolchain version must be a bounded, printable identifier.');
        }

        $retrievedAt = date_create_immutable($plan->provenance->retrievedAt);
        if ($retrievedAt === false || $retrievedAt->format('P') !== '+00:00') {
            throw new InvalidArgumentException('Connector candidate provenance must contain an explicit UTC retrieval time.');
        }

        $canonicalPlan = $this->canonicalize($plan->toArray());
        $inputPlanSha256 = hash('sha256', json_encode(
            $canonicalPlan,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $candidateId = hash('sha256', implode("\n", [
            'connector-candidate-v1',
            $inputPlanSha256,
            self::GENERATOR_VERSION,
            self::TEMPLATE_VERSION,
            $toolchainVersion,
        ]));

        $className = $this->className($plan->providerKey);
        $timestamp = $retrievedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $manifestPath = 'connector-candidates/'.$plan->providerKey.'/candidate.json';
        $adapterPath = 'connector-candidates/'.$plan->providerKey.'/src/'.$className.'ConnectorCandidate.php';
        $testPath = 'connector-candidates/'.$plan->providerKey.'/tests/'.$className.'ConnectorCandidateTest.php';

        $manifest = [
            'schema_version' => 1,
            'candidate_id' => $candidateId,
            'workspace_id' => $plan->workspaceId,
            'provider_key' => $plan->providerKey,
            'status' => 'candidate_only',
            'executable' => false,
            'authority_granted' => false,
            'generator' => [
                'name' => self::class,
                'version' => self::GENERATOR_VERSION,
                'template_version' => self::TEMPLATE_VERSION,
                'toolchain_version' => $toolchainVersion,
            ],
            'input_plan_sha256' => $inputPlanSha256,
            'source_provenance' => [
                'source_origin' => $this->sourceOrigin($plan->provenance->sourceUri),
                'source_uri_sha256' => hash('sha256', $plan->provenance->sourceUri),
                'media_type' => $plan->provenance->mediaType,
                'retrieved_at' => $timestamp,
                'declared_version' => $plan->provenance->declaredVersion,
                'raw_sha256' => $plan->provenance->rawSha256,
                'normalized_sha256' => $plan->provenance->normalizedSha256,
                'parser_version' => $plan->provenance->parserVersion,
            ],
            'plan_summary' => [
                'capability_count' => count($plan->capabilities),
                'auth_scheme_count' => count($plan->authSchemes),
                'server_count' => count($plan->servers),
                'unknown_semantic_count' => count($plan->unknownSemantics),
            ],
            'artifacts' => [$adapterPath, $testPath],
            'dependencies' => [],
            'activation' => [
                'allowed' => false,
                'reason' => 'Candidate artifacts require independent review and a separate authorized promotion path.',
            ],
        ];

        $manifestContent = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        // All interpolated identifiers are constrained slugs or canonical UTC timestamps.
        // Provider descriptions, operation IDs, URLs, and scopes are never copied into PHP.
        $adapterContent = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Generated\\ConnectorCandidates;\n\nuse App\\Modules\\Providers\\Domain\\Connectors\\ConnectorManifest;\nuse App\\Modules\\Providers\\Domain\\Connectors\\Contracts\\ConnectorAdapter;\nuse DateTimeImmutable;\n\nfinal readonly class {$className}ConnectorCandidate implements ConnectorAdapter\n{\n    public function manifest(): ConnectorManifest\n    {\n        return new ConnectorManifest(\n            connectorKey: ".var_export('candidate-'.$plan->providerKey, true).",\n            connectorVersion: '0.0.0-candidate',\n            apiVersionStrategy: 'manual-review-required',\n            documentationUrl: '',\n            observedAt: new DateTimeImmutable(".var_export($timestamp, true)."),\n            sandboxLimitations: ['candidate_only', 'no_provider_io', 'no_credentials'],\n            capabilities: [],\n            metadata: ['activation_allowed' => false],\n        );\n    }\n}\n";

        $testContent = "<?php\n\nnamespace Tests\\Generated\\ConnectorCandidates;\n\nuse Generated\\ConnectorCandidates\\{$className}ConnectorCandidate;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$className}ConnectorCandidateTest extends TestCase\n{\n    public function testGeneratedAdapterRemainsAnInertCandidate(): void\n    {\n        require_once __DIR__.'/../src/{$className}ConnectorCandidate.php';\n\n        \$manifest = (new {$className}ConnectorCandidate)->manifest();\n\n        self::assertSame('candidate-".$plan->providerKey."', \$manifest->connectorKey);\n        self::assertSame('0.0.0-candidate', \$manifest->connectorVersion);\n        self::assertSame([], \$manifest->capabilities);\n        self::assertContains('candidate_only', \$manifest->sandboxLimitations);\n        self::assertContains('no_provider_io', \$manifest->sandboxLimitations);\n        self::assertContains('no_credentials', \$manifest->sandboxLimitations);\n        self::assertFalse(\$manifest->metadata['activation_allowed']);\n    }\n}\n";

        $files = [];
        foreach ([
            [$manifestPath, $manifestContent],
            [$adapterPath, $adapterContent],
            [$testPath, $testContent],
        ] as [$path, $content]) {
            $files[] = [
                'path' => $path,
                'content' => $content,
                'sha256' => hash('sha256', $content),
            ];
        }

        return new ConnectorGeneratedCandidate(
            workspaceId: $plan->workspaceId,
            providerKey: $plan->providerKey,
            candidateId: $candidateId,
            generatorVersion: self::GENERATOR_VERSION,
            templateVersion: self::TEMPLATE_VERSION,
            toolchainVersion: $toolchainVersion,
            inputPlanSha256: $inputPlanSha256,
            files: $files,
        );
    }

    private function className(string $providerKey): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $providerKey)));
    }

    private function sourceOrigin(string $sourceUri): ?string
    {
        $parts = parse_url($sourceUri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
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
