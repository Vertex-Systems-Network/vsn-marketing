<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Application\ConnectorFactory\ConnectorDescriptionIngestor;
use App\Modules\Providers\Application\ConnectorFactory\OpenApiConnectorPlanner;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0083ConnectorFactoryTest extends TestCase
{
    public function test_openapi_ingestion_preserves_raw_and_deterministic_normalized_identity(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();

        $first = $ingestor->ingest(
            'workspace-a',
            'https://docs.example.test/openapi.json',
            'application/json',
            '{"info":{"title":"Example","version":"1"},"openapi":"3.2.1","paths":{}}',
            '2026-10-08T00:00:00+00:00',
        );

        $second = $ingestor->ingest(
            'workspace-a',
            'https://docs.example.test/openapi.json',
            'application/json',
            '{"paths":{},"openapi":"3.2.1","info":{"version":"1","title":"Example"}}',
            '2026-10-08T00:00:00+00:00',
        );

        self::assertNotSame($first->provenance->rawSha256, $second->provenance->rawSha256);
        self::assertSame($first->provenance->normalizedSha256, $second->provenance->normalizedSha256);
        self::assertSame('3.2.1', $first->provenance->declaredVersion);
        self::assertSame('openapi', $first->sourceType);
    }

    public function test_external_references_and_active_content_fail_closed(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();

        foreach ([
            '{"openapi":"3.2.1","paths":{},"components":{"schemas":{"Bad":{"$ref":"https://internal.example/schema.json"}}}}',
            '{"openapi":"3.2.1","info":{"description":"<script>alert(1)</script>"},"paths":{}}',
        ] as $source) {
            try {
                $ingestor->ingest(
                    'workspace-a',
                    'https://docs.example.test/openapi.json',
                    'application/json',
                    $source,
                    '2026-10-08T00:00:00+00:00',
                );
                self::fail('Unsafe connector source was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unsupported_media_oversized_sources_and_missing_workspace_fail_closed(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();

        $cases = [
            ['', 'https://docs.example.test/openapi.json', 'application/json', '{"openapi":"3.2.1","paths":{}}'],
            ['workspace-a', 'https://docs.example.test/openapi.yaml', 'application/yaml', "openapi: 3.2.1\npaths: {}"],
            ['workspace-a', 'https://docs.example.test/openapi.json', 'application/json', str_repeat('x', ConnectorDescriptionIngestor::MAX_BYTES + 1)],
        ];

        foreach ($cases as [$workspace, $uri, $media, $contents]) {
            try {
                $ingestor->ingest($workspace, $uri, $media, $contents, '2026-10-08T00:00:00+00:00');
                self::fail('Out-of-policy connector source was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_plain_documentation_is_data_only_and_cannot_create_capabilities(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();
        $planner = new OpenApiConnectorPlanner();

        $description = $ingestor->ingest(
            'workspace-a',
            'https://docs.example.test/reference.md',
            'text/markdown',
            "# API\r\nUse the provider API.   \r\n",
            '2026-10-08T00:00:00+00:00',
            'docs-v4',
        );

        $plan = $planner->plan($description, 'example');

        self::assertSame("# API\nUse the provider API.\n", $description->normalizedText);
        self::assertSame([], $plan->capabilities);
        self::assertSame(['documentation_only:no_capabilities_inferred'], $plan->unknownSemantics);
        self::assertFalse($plan->executable);
        self::assertFalse($plan->authorityGranted);
        self::assertSame('candidate_only', $plan->activationState);
    }

    public function test_openapi_plan_extracts_typed_endpoints_auth_scopes_and_declared_limits_without_authority(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();
        $planner = new OpenApiConnectorPlanner();

        $source = json_encode([
            'openapi' => '3.2.1',
            'servers' => [
                ['url' => 'https://api.example.test'],
            ],
            'components' => [
                'securitySchemes' => [
                    'OAuth' => [
                        'type' => 'oauth2',
                        'flows' => [
                            'clientCredentials' => [
                                'tokenUrl' => 'https://auth.example.test/token',
                                'scopes' => [
                                    'contacts:write' => 'Write contacts',
                                    'contacts:read' => 'Read contacts',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'security' => [
                ['OAuth' => ['contacts:read']],
            ],
            'x-rate-limit' => 100,
            'paths' => [
                '/contacts' => [
                    'get' => [
                        'operationId' => 'listContacts',
                        'x-rate-limit' => 10,
                    ],
                    'post' => [
                        'operationId' => 'createContact',
                        'security' => [
                            ['OAuth' => ['contacts:write']],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $description = $ingestor->ingest(
            'workspace-a',
            'https://docs.example.test/openapi.json',
            'application/vnd.oai.openapi+json; charset=utf-8',
            $source,
            '2026-10-08T00:00:00+00:00',
        );

        $plan = $planner->plan($description, 'example');

        self::assertCount(2, $plan->capabilities);
        self::assertSame(['https://api.example.test'], $plan->servers);
        self::assertSame('oauth2', $plan->authSchemes['OAuth']['type']);
        self::assertSame(['contacts:read', 'contacts:write'], $plan->authSchemes['OAuth']['scopes']);
        self::assertFalse($plan->authSchemes['OAuth']['authority_granted']);

        $get = $plan->capabilities[0];
        self::assertSame('GET', $get->method);
        self::assertSame('/contacts', $get->path);
        self::assertSame('listContacts', $get->operationId);
        self::assertSame(['OAuth'], $get->authSchemes);
        self::assertSame(['contacts:read'], $get->scopes['OAuth']);
        self::assertSame(10, $get->declaredLimits['x-rate-limit']);

        self::assertFalse($plan->executable);
        self::assertFalse($plan->authorityGranted);
        self::assertSame('candidate_only', $plan->activationState);
    }

    public function test_plan_contract_rejects_cross_workspace_or_authority_promotion(): void
    {
        $ingestor = new ConnectorDescriptionIngestor();
        $description = $ingestor->ingest(
            'workspace-a',
            'https://docs.example.test/openapi.json',
            'application/json',
            '{"openapi":"3.2.1","paths":{}}',
            '2026-10-08T00:00:00+00:00',
        );

        foreach ([
            ['workspace-b', false, false, 'candidate_only'],
            ['workspace-a', true, false, 'candidate_only'],
            ['workspace-a', false, true, 'candidate_only'],
            ['workspace-a', false, false, 'active'],
        ] as [$workspace, $executable, $authority, $state]) {
            try {
                new ConnectorPlanCandidate(
                    workspaceId: $workspace,
                    providerKey: 'example',
                    provenance: $description->provenance,
                    capabilities: [],
                    authSchemes: [],
                    servers: [],
                    unknownSemantics: [],
                    executable: $executable,
                    authorityGranted: $authority,
                    activationState: $state,
                );
                self::fail('Unsafe connector plan promotion was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
