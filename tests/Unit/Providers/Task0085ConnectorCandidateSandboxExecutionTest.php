<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Application\ConnectorFactory\ConnectorCandidateGenerator;
use App\Modules\Providers\Application\ConnectorFactory\ConnectorDescriptionIngestor;
use App\Modules\Providers\Application\ConnectorFactory\OpenApiConnectorPlanner;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorGeneratedCandidate;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class Task0085ConnectorCandidateSandboxExecutionTest extends TestCase
{
    private const SANDBOX_IMAGE = 'php@sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319';

    public function test_generated_candidate_is_confined_to_an_ephemeral_unprivileged_sandbox(): void
    {
        $docker = new Process(['docker', 'info', '--format', '{{.ServerVersion}}']);
        $docker->setTimeout(10);
        $docker->run();

        if ($docker->isSuccessful() === false) {
            if (getenv('CI') === 'true') {
                self::fail('Docker is required for the CI sandbox execution gate: '.$docker->getErrorOutput());
            }

            self::markTestSkipped('Docker is not available for local sandbox execution.');
        }

        $generated = (new ConnectorCandidateGenerator)->generate($this->plan(), 'php-8.3.35');
        $values = $generated->toArray();
        $adapter = null;
        foreach ($values['files'] as $file) {
            if (str_contains($file['path'], '/src/')) {
                $adapter = $file;
                break;
            }
        }
        self::assertIsArray($adapter);

        // This adversarial payload is never evaluated by the host PHP process.
        $attack = <<<'PHP'
$GLOBALS['sandbox_results'] = [
    'candidate_write_blocked' => @file_put_contents('/candidate/escape.txt', 'x') === false,
    'rootfs_write_blocked' => @file_put_contents('/rootfs-escape.txt', 'x') === false,
    'network_blocked' => @fsockopen('1.1.1.1', 80, $errno, $error, 0.5) === false,
    'secrets_absent' => getenv('APP_KEY') === false && getenv('GITHUB_TOKEN') === false,
    'ephemeral_tmp_write_allowed' => @file_put_contents('/tmp/candidate-probe', 'temporary') === 9,
];
PHP;
        $candidateBytes = $adapter['content']."\n".$attack."\n";
        $candidateSha256 = hash('sha256', $candidateBytes);
        $candidate = new ConnectorGeneratedCandidate(
            workspaceId: $values['workspace_id'],
            providerKey: $values['provider_key'],
            candidateId: $values['candidate_id'],
            generatorVersion: $values['generator_version'],
            templateVersion: $values['template_version'],
            toolchainVersion: $values['toolchain_version'],
            inputPlanSha256: $values['input_plan_sha256'],
            files: [[
                'path' => $adapter['path'],
                'content' => $candidateBytes,
                'sha256' => $candidateSha256,
            ], ...array_values(array_filter(
                $values['files'],
                static fn (array $file): bool => $file['path'] !== $adapter['path'],
            ))],
        );

        $directory = sys_get_temp_dir().'/vsn-connector-sandbox-'.bin2hex(random_bytes(8));
        $containerName = 'vsn-connector-sandbox-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0755));

        try {
            self::assertSame(
                strlen($candidateBytes),
                file_put_contents($directory.'/ExampleConnectorCandidate.php', $candidateBytes),
            );
            self::assertNotFalse(file_put_contents($directory.'/probe.php', $this->probe()));
            self::assertTrue(chmod($directory.'/ExampleConnectorCandidate.php', 0444));
            self::assertTrue(chmod($directory.'/probe.php', 0444));

            $command = new Process([
                'docker', 'run', '--rm', '--name', $containerName,
                '--network=none',
                '--read-only',
                '--user=65532:65532',
                '--cap-drop=ALL',
                '--security-opt=no-new-privileges',
                '--security-opt=seccomp=builtin',
                '--memory=128m',
                '--memory-swap=128m',
                '--cpus=0.5',
                '--pids-limit=32',
                '--ulimit=nofile=64:64',
                '--stop-timeout=2',
                '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m,mode=1777',
                '--mount=type=bind,src='.$directory.',dst=/candidate,readonly',
                self::SANDBOX_IMAGE,
                'php',
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=0',
                '-d', 'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec',
                '/candidate/probe.php',
            ]);
            $command->setTimeout(25);
            $command->run();

            self::assertTrue($command->isSuccessful(), $command->getErrorOutput());
            $results = json_decode($command->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            foreach ([
                'candidate_write_blocked',
                'rootfs_write_blocked',
                'network_blocked',
                'secrets_absent',
                'ephemeral_tmp_write_allowed',
                'generated_manifest_is_candidate',
            ] as $control) {
                self::assertTrue($results[$control] ?? false, 'Sandbox control failed: '.$control);
            }
            self::assertFileDoesNotExist($directory.'/escape.txt');

            fwrite(STDOUT, 'CONNECTOR_SANDBOX_EVIDENCE '.json_encode([
                'candidate_id' => $candidate->candidateId,
                'candidate_sha256' => $candidateSha256,
                'image' => self::SANDBOX_IMAGE,
                'controls' => [
                    'network' => 'none',
                    'input_mount' => 'read_only',
                    'root_filesystem' => 'read_only',
                    'user' => '65532:65532',
                    'capabilities' => 'all_dropped',
                    'no_new_privileges' => true,
                    'seccomp' => 'builtin_default',
                    'memory_mib' => 128,
                    'cpu_cores' => 0.5,
                    'process_limit' => 32,
                    'host_timeout_seconds' => 25,
                    'container_removed_after_run' => true,
                ],
                'observations' => $results,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } finally {
            $cleanup = new Process(['docker', 'rm', '--force', $containerName]);
            $cleanup->setTimeout(5);
            $cleanup->run();

            @unlink($directory.'/probe.php');
            @unlink($directory.'/ExampleConnectorCandidate.php');
            @rmdir($directory);
        }
    }

    private function probe(): string
    {
        return <<<'PHP'
<?php
namespace App\Modules\Providers\Domain\Connectors\Contracts {
    interface ConnectorAdapter {}
}
namespace App\Modules\Providers\Domain\Connectors {
    final readonly class ConnectorManifest
    {
        public function __construct(
            public string $connectorKey,
            public string $connectorVersion,
            public string $apiVersionStrategy,
            public string $documentationUrl,
            public \DateTimeImmutable $observedAt,
            public array $sandboxLimitations,
            public array $capabilities,
            public array $metadata,
        ) {}
    }
}
namespace {
    require '/candidate/ExampleConnectorCandidate.php';
    $manifest = (new \Generated\ConnectorCandidates\ExampleConnectorCandidate)->manifest();
    $results = $GLOBALS['sandbox_results'] ?? [];
    $results['generated_manifest_is_candidate'] =
        $manifest->connectorKey === 'candidate-example'
        && $manifest->capabilities === []
        && ($manifest->metadata['activation_allowed'] ?? true) === false;
    echo json_encode($results, JSON_THROW_ON_ERROR);
}
PHP;
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
