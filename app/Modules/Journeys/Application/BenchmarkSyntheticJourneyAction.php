<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Consent\Application\GetEffectiveConsent;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use Illuminate\Database\DatabaseManager;

/** Isolated benchmark stub; never resolves a provider or sends a message. */
final readonly class BenchmarkSyntheticJourneyAction implements JourneyActionExecutor
{
    public function __construct(
        private DatabaseManager $database,
        private GetEffectiveConsent $consent,
        private DatabaseSuppressionRepository $suppression,
    ) {}

    public function checks(string $workspaceId, string $subjectId, array $node): array
    {
        $this->assertSynthetic($workspaceId, $subjectId, $node);
        $workspace = $this->database->table('workspaces')->where('id', $workspaceId)->first();
        if ($workspace === null) {
            throw new JourneyDefinitionException('synthetic_action_scope_denied', '$.action');
        }
        $context = new TenantContext((string) $workspace->organization_id, $workspaceId, null, 'benchmark-system');

        return [
            'provider_capability' => true,
            'consent' => $this->consent->handle($context, $subjectId, 'email', 'marketing')->isGranted(),
            'suppression_clear' => ! $this->suppression->isSuppressed($workspaceId, $subjectId, 'email', 'marketing'),
            'authorized' => true, 'quota_available' => true, 'idempotent' => true,
        ];
    }

    public function execute(string $workspaceId, string $subjectId, array $node, string $attemptKey): void
    {
        $this->assertSynthetic($workspaceId, $subjectId, $node);
        if (! preg_match('/^[0-9a-f]{64}$/D', $attemptKey)) {
            throw new JourneyDefinitionException('invalid_synthetic_attempt', '$.action');
        }
        // This is a deterministic in-process no-op. It proves queue traversal and gate
        // invocation, not real consent/provider latency or production action policy.
    }

    private function assertSynthetic(string $workspaceId, string $subjectId, array $node): void
    {
        if (app()->environment('benchmark') !== true || getenv('RBT052_SYNTHETIC_ACTIONS') !== '1'
            || ($node['config']['capability'] ?? null) !== 'benchmark.stub'
            || ($node['config']['input'] ?? null) !== ['channel' => 'email', 'purpose' => 'marketing']
            || ! $this->database->table('workspaces')->where('id', $workspaceId)->where('slug', 'like', 'journey-bench-%')->exists()
            || ! $this->database->table('contacts')->where('workspace_id', $workspaceId)->where('id', $subjectId)->exists()) {
            throw new JourneyDefinitionException('synthetic_action_scope_denied', '$.action');
        }
    }
}
