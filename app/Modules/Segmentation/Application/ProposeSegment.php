<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\Contracts\SegmentProposalProvider;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentProposalGuard;
use App\Modules\Segmentation\Domain\SegmentProposalResponse;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class ProposeSegment
{
    public const AUDIT_ACTION = 'segment.proposal.generated';

    public function __construct(
        private SegmentProposalProvider $provider,
        private SegmentFieldRegistry $fields,
        private SegmentValidator $validator,
        private SegmentProposalGuard $guard,
        private DeterministicSegmentCompiler $compiler,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function handle(string $intent, TenantContext $scope, User $actor): array
    {
        $intent = trim($intent);
        $intentFingerprint = hash('sha256', $intent);

        if ((string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::AI_EXECUTE)
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_READ)) {
            return ['status' => 'permission_denied', 'code' => 'workspace_permission_denied'];
        }

        $maxCharacters = max(1, (int) config('segmentation.max_proposal_characters', 2000));
        if ($intent === '' || mb_strlen($intent) > $maxCharacters) {
            return ['status' => 'invalid_input', 'code' => 'intent_length_invalid'];
        }

        if ($this->guard->containsSensitiveLiteral($intent)) {
            $this->record($scope, $intentFingerprint, 'input_rejected', null);

            return ['status' => 'input_rejected', 'code' => 'remove_personal_or_secret_values'];
        }

        if (! $this->provider->available()) {
            $this->record($scope, $intentFingerprint, 'unavailable', null);

            return ['status' => 'unavailable', 'code' => 'no_approved_ai_route'];
        }

        try {
            // Exactly one provider call: retry policy remains owned by a future approved gateway route.
            $response = $this->provider->propose($intent, $this->schema($scope));
        } catch (Throwable) {
            $this->record($scope, $intentFingerprint, 'failed', null);

            return ['status' => 'failed', 'code' => 'provider_unavailable'];
        }

        if ($response->status === 'clarification_required') {
            $questions = array_values(array_filter(array_map(
                static fn (string $question): string => mb_substr(strip_tags(trim($question)), 0, 240),
                $response->clarificationQuestions,
            ), static fn (string $question): bool => $question !== ''));
            $this->record($scope, $intentFingerprint, 'clarification_required', null);

            return ['status' => 'clarification_required', 'questions' => $questions];
        }

        if ($response->status !== 'proposed' || $response->definition === null) {
            $status = $response->status === 'unavailable' ? 'unavailable' : 'failed';
            $this->record($scope, $intentFingerprint, $status, null);

            return ['status' => $status, 'code' => $status === 'unavailable' ? 'no_approved_ai_route' : 'provider_refused_or_failed'];
        }

        try {
            $definition = $this->validator->normalize($response->definition);
            $schema = $this->schema($scope);
            $this->guard->assertSafeDefinition($definition);
            $this->assertAllowedReferences($definition['root'], $schema, '$.root');
            $compiled = $this->compiler->compile(
                $definition,
                $scope,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (SegmentDefinitionException) {
            $this->record($scope, $intentFingerprint, 'invalid_proposal', null);

            return ['status' => 'invalid', 'code' => 'proposal_failed_deterministic_validation'];
        } catch (Throwable) {
            $this->record($scope, $intentFingerprint, 'failed', null);

            return ['status' => 'failed', 'code' => 'proposal_validation_unavailable'];
        }

        $this->record($scope, $intentFingerprint, 'proposed', $compiled->definitionHash);

        return [
            'status' => 'proposed',
            'definition' => $definition,
            'definition_hash' => $compiled->definitionHash,
            'explanation' => $this->explain($definition['root']),
            'route_version' => $this->safeRouteVersion($response),
        ];
    }

    /** @return array<string, mixed> */
    private function schema(TenantContext $scope): array
    {
        $fields = [];
        foreach ($this->fields->availableTo([PermissionCatalog::CONTACT_READ]) as $id => $field) {
            $fields[] = [
                'id' => $id,
                'type' => $field['type'],
                'operators' => $field['type'] === 'timestamp'
                    ? ['before', 'after', 'on_or_before', 'on_or_after', 'is_set', 'is_not_set']
                    : ['equals', 'not_equals', 'is_set', 'is_not_set'],
            ];
        }

        $eventLimit = max(1, (int) config('segmentation.max_proposal_events', 250));
        $eventNames = $this->database->table('event_types')
            ->where('workspace_id', $scope->workspaceId)
            ->orderBy('canonical_name')
            ->limit($eventLimit)
            ->pluck('canonical_name')
            ->all();
        $eventNames = array_values(array_unique(array_filter(
            $eventNames,
            static fn (mixed $name): bool => is_string($name) && preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $name) === 1,
        )));

        return [
            'schema_version' => 1,
            'output' => [
                'schema_version' => 1,
                'subject' => 'contact',
                'root' => 'registered group, attribute, or event node',
                'additional_properties' => false,
            ],
            'fields' => $fields,
            'events' => $eventNames,
            'limits' => [
                'maximum_depth' => (int) config('segmentation.max_depth', 8),
                'maximum_nodes' => (int) config('segmentation.max_nodes', 100),
                'maximum_event_days' => (int) config('segmentation.max_event_days', 365),
            ],
            'unsupported' => ['sql', 'database_identifiers', 'joins', 'functions', 'json_paths', 'regex', 'list_or_tag_ids', 'event_properties'],
        ];
    }

    /** @param array<string, mixed> $node @param array<string, mixed> $schema */
    private function assertAllowedReferences(array $node, array $schema, string $path): void
    {
        if ($node['type'] === 'group') {
            foreach ($node['children'] as $index => $child) {
                $this->assertAllowedReferences($child, $schema, $path.'.children.'.$index);
            }

            return;
        }

        if ($node['type'] === 'not') {
            $this->assertAllowedReferences($node['child'], $schema, $path.'.child');

            return;
        }

        if ($node['type'] === 'attribute') {
            $allowedFields = array_column($schema['fields'], 'id');
            if (! in_array($node['field'], $allowedFields, true)) {
                throw new SegmentDefinitionException('field_not_authorized', $path.'.field');
            }

            return;
        }

        if ($node['type'] === 'event') {
            if (! in_array($node['name'], $schema['events'], true)) {
                throw new SegmentDefinitionException('unknown_or_foreign_event', $path.'.name');
            }

            return;
        }

        // AI cannot choose opaque list/tag identifiers. Task46 may expose a deterministic picker.
        throw new SegmentDefinitionException('unsupported_ai_node', $path.'.type');
    }

    private function containsSensitiveLiteral(string $intent): bool
    {
        return preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $intent) === 1
            || preg_match('/\b(?:sk|rk|ghp|github_pat)_[A-Za-z0-9_-]{10,}\b/i', $intent) === 1;
    }

    private function record(TenantContext $scope, string $intentFingerprint, string $status, ?string $definitionHash): void
    {
        $this->audit->record(
            workspaceId: $scope->workspaceId,
            brandId: $scope->brandId,
            actorId: $scope->actorId,
            action: self::AUDIT_ACTION,
            evidence: [
                'schema_version' => 1,
                'status' => $status,
                'intent_fingerprint' => $intentFingerprint,
                'definition_hash' => $definitionHash,
            ],
        );
    }

    private function safeRouteVersion(SegmentProposalResponse $response): ?string
    {
        return is_string($response->routeVersion)
            && preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $response->routeVersion) === 1
            ? $response->routeVersion
            : null;
    }

    /** @param array<string, mixed> $node */
    private function explain(array $node): string
    {
        if ($node['type'] === 'group') {
            $operator = $node['operator'] === 'all' ? 'ALL of' : 'ANY of';
            $children = array_map(fn (array $child): string => '- '.$this->explain($child), $node['children']);

            return $operator.":\n".implode("\n", $children);
        }

        if ($node['type'] === 'not') {
            return 'NOT ('.$this->explain($node['child']).')';
        }

        if ($node['type'] === 'attribute') {
            $value = array_key_exists('value', $node)
                ? ' '.json_encode($node['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '';

            return $node['field'].' '.$node['operator'].$value;
        }

        return 'Event '.$node['name'].' '.$node['mode'].' in '.json_encode($node['window'], JSON_UNESCAPED_SLASHES);
    }
}
