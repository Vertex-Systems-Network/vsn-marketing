<?php

namespace Tests\Support\Publishing;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Application\Publication\PublicationStatusReconciliationService;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Task0040PublicationOperationFixture
{
    /**
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $requiredScopes
     * @param  list<string>  $requiredRoles
     */
    public static function addCapability(
        array $fixture,
        string $operation,
        string $suffix,
        string $support = 'supported',
        array $requiredScopes = ['publish.write'],
        array $requiredRoles = ['publisher'],
        string $observedAt = '2026-07-15T13:31:00+00:00',
        ?string $freshUntil = '2026-07-16T13:31:00+00:00',
    ): string {
        $id = (string) Str::uuid();
        DB::table('provider_capabilities')->insert([
            'id' => $id,
            'workspace_id' => $fixture['context']->workspaceId,
            'provider_id' => $fixture['providerId'],
            'connection_id' => $fixture['providerConnectionId'],
            'operation' => $operation,
            'support_status' => $support,
            'required_scopes' => json_encode($requiredScopes, JSON_THROW_ON_ERROR),
            'required_roles' => json_encode($requiredRoles, JSON_THROW_ON_ERROR),
            'constraints' => json_encode([], JSON_THROW_ON_ERROR),
            'source_url' => 'https://example.test/task0040/operation/'.$suffix,
            'source_version' => '2026-09-'.$suffix,
            'observed_at' => new \DateTimeImmutable($observedAt),
            'fresh_until' => $freshUntil === null ? null : new \DateTimeImmutable($freshUntil),
            'created_at' => new \DateTimeImmutable($observedAt),
            'updated_at' => new \DateTimeImmutable($observedAt),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $fixture */
    public static function terminalAttempt(array $fixture, PublicationAttemptState $terminal): PublicationAttempt
    {
        $attempts = app(DatabasePublicationAttemptRepository::class);
        $attempt = app(PublicationAttemptService::class)->prepare(
            workspaceId: $fixture['context']->workspaceId,
            executionIntentId: $fixture['executionIntent']->id,
            targetId: $fixture['target']->id,
            at: new \DateTimeImmutable('2026-07-15T13:30:20+00:00'),
        );
        $attempt = $attempts->transitionState(
            $attempt->transitionTo(
                PublicationAttemptState::Dispatching,
                new \DateTimeImmutable('2026-07-15T13:30:21+00:00'),
            ),
            1,
        );

        return $attempts->transitionState(
            $attempt->transitionTo($terminal, new \DateTimeImmutable('2026-07-15T13:30:22+00:00')),
            2,
        );
    }

    /** @param array<string, mixed> $fixture */
    public static function observe(
        array $fixture,
        PublicationAttempt $attempt,
        ProviderOperationStatus $status,
        string $suffix,
    ): void {
        app(PublicationStatusReconciliationService::class)->observe(
            workspaceId: $fixture['context']->workspaceId,
            publicationAttemptId: $attempt->id,
            providerOperationId: 'provider-operation-'.$suffix,
            normalizedStatus: $status,
            providerStatus: strtoupper($status->value),
            source: ReconciliationSource::Webhook,
            sourceReference: 'event-'.$suffix,
            providerObservedAt: new \DateTimeImmutable('2026-07-15T13:31:00+00:00'),
            receivedAt: new \DateTimeImmutable('2026-07-15T13:31:01+00:00'),
        );
    }
}
