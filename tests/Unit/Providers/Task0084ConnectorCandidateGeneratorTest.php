<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidateGenerator;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorDescriptionIngestor;
use App\Modules\Providers\Application\ConnectorFactory\OpenApiConnectorPlanner;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorGeneratedCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0084ConnectorCandidateGeneratorTest extends TestCase
{
    public function test_generation_is_deterministic_and_records_pinned_input_toolchain_and_source_provenance(): void
    {
        $plan = $this->plan();
        $generator = new ConnectorCandidateGenerator;

        $first = $generator->generate($plan, 'php-8.3.0');
        $second = $generator->generate($plan, 'php-8.3.0');

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame(64, strlen($first->inputPlanSha256));
        self::assertSame(64, strlen($first->candidateId));
        self::assertSame('task0084-v1', $first->generatorVersion);
        self::assertSame('connector-shell-v1', $first->templateVersion);
        self::assertSame('php-8.3.0', $first->toolchainVersion);
        self::assertCount(3, $first->files);
        self::assertSame('candidate_only', $first->activationState);
        self::assertFalse($first->executable);
        self::assertFalse($first->authorityGranted);

        $manifest = json_decode($first->files[0]['content'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($first->inputPlanSha256, $manifest['input_plan_sha256']);
        self::assertSame($plan->provenance->rawSha256, $manifest['source_provenance']['raw_sha256']);
        self::assertSame($plan->provenance->normalizedSha256, $manifest['source_provenance']['normalized_sha256']);
        self::assertSame('https://docs.example.test', $manifest['source_provenance']['source_origin']);
        self::assertSame([], $manifest['dependencies']);
        self::assertFalse($manifest['activation']['allowed']);
    }

    public function test_untrusted_operation_names_and_source_credentials_never_enter_generated_php_or_manifest(): void
    {
        $plan = $this->plan(
            sourceUri: 'https://user:secret@docs.example.test/private?token=hidden',
            operationId: "evil'); system('touch /tmp/connector-candidate-pwned'); //",
        );
        $candidate = (new ConnectorCandidateGenerator)->generate($plan, 'php-8.3.0');
        $allContent = implode("\n", array_column($candidate->files, 'content'));

        self::assertStringNotContainsString('secret', $allContent);
        self::assertStringNotContainsString('hidden', $allContent);
        self::assertStringNotContainsString('system(', $allContent);
        self::assertStringNotContainsString('touch /tmp', $allContent);
        self::assertStringContainsString('candidate_only', $allContent);
        self::assertSame(
            'https://docs.example.test',
            json_decode($candidate->files[0]['content'], true, flags: JSON_THROW_ON_ERROR)['source_provenance']['source_origin'],
        );
    }

    public function test_provider_key_and_toolchain_are_validated_before_any_artifact_is_returned(): void
    {
        $generator = new ConnectorCandidateGenerator;

        try {
            $generator->generate($this->plan(providerKey: '../escape'), 'php-8.3.0');
            self::fail('A path-traversal provider key was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $generator->generate($this->plan(providerKey: '3example'), 'php-8.3.0');
            self::fail('A provider key that cannot form a PHP class was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $generator->generate($this->plan(), 'php-8.3.0;system');
    }

    public function test_hidden_payloads_cannot_add_workflows_runtime_commands_or_unreviewed_dependencies(): void
    {
        $payload = 'HIDDEN_PAYLOAD_91; composer require attacker/package; system("id"); .github/workflows/pwn.yml';
        $candidate = (new ConnectorCandidateGenerator)->generate(
            $this->plan(hostilePayload: $payload),
            'php-8.3.0',
        );
        $allContent = implode("\n", array_column($candidate->files, 'content'));
        $manifest = json_decode($candidate->files[0]['content'], true, flags: JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('HIDDEN_PAYLOAD_91', $allContent);
        self::assertStringNotContainsString('composer require', $allContent);
        self::assertStringNotContainsString('system(', $allContent);
        self::assertStringNotContainsString('.github/workflows', $allContent);
        self::assertSame([], $manifest['dependencies']);
        self::assertSame([
            'connector-candidates/example/candidate.json',
            'connector-candidates/example/src/ExampleConnectorCandidate.php',
            'connector-candidates/example/tests/ExampleConnectorCandidateTest.php',
        ], array_column($candidate->files, 'path'));
    }

    public function test_artifact_paths_and_hashes_are_fixed_and_content_bound(): void
    {
        $candidate = (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0');

        self::assertSame([
            'connector-candidates/example/candidate.json',
            'connector-candidates/example/src/ExampleConnectorCandidate.php',
            'connector-candidates/example/tests/ExampleConnectorCandidateTest.php',
        ], array_column($candidate->files, 'path'));

        foreach ($candidate->files as $file) {
            self::assertSame(hash('sha256', $file['content']), $file['sha256']);
            self::assertStringStartsWith('connector-candidates/example/', $file['path']);
            self::assertStringNotContainsString('..', $file['path']);
        }
    }

    public function test_candidate_value_object_rejects_activation_and_out_of_allowlist_paths(): void
    {
        $candidate = (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.0');
        $values = $candidate->toArray();

        try {
            new ConnectorGeneratedCandidate(
                workspaceId: $values['workspace_id'],
                providerKey: $values['provider_key'],
                candidateId: $values['candidate_id'],
                generatorVersion: $values['generator_version'],
                templateVersion: $values['template_version'],
                toolchainVersion: $values['toolchain_version'],
                inputPlanSha256: $values['input_plan_sha256'],
                files: $values['files'],
                executable: true,
            );
            self::fail('Executable candidate state was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $files = $values['files'];
        $files[0]['path'] = 'connector-candidates/example/../../app/Providers.php';

        $this->expectException(InvalidArgumentException::class);
        new ConnectorGeneratedCandidate(
            workspaceId: $values['workspace_id'],
            providerKey: $values['provider_key'],
            candidateId: $values['candidate_id'],
            generatorVersion: $values['generator_version'],
            templateVersion: $values['template_version'],
            toolchainVersion: $values['toolchain_version'],
            inputPlanSha256: $values['input_plan_sha256'],
            files: $files,
        );
    }

    private function plan(
        string $sourceUri = 'https://docs.example.test/openapi.json',
        string $operationId = 'listContacts',
        string $providerKey = 'example',
        ?string $hostilePayload = null,
    ): \App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate {
        $source = json_encode([
            'openapi' => '3.2.1',
            'info' => ['title' => 'Example API', 'version' => '1'],
            'description' => $hostilePayload,
            'components' => [
                'securitySchemes' => [
                    'ExampleKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
            'paths' => [
                '/contacts' => [
                    'get' => ['operationId' => $operationId, 'description' => $hostilePayload],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $description = (new ConnectorDescriptionIngestor)->ingest(
            workspaceId: 'workspace-a',
            sourceUri: $sourceUri,
            mediaType: 'application/json',
            contents: $source,
            retrievedAt: '2026-10-08T00:00:00+00:00',
        );

        return (new OpenApiConnectorPlanner)->plan($description, $providerKey);
    }
}
