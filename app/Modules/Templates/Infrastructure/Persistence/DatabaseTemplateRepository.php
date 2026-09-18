<?php

namespace App\Modules\Templates\Infrastructure\Persistence;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Templates\Domain\ComponentLifecycle;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\ReusableComponent;
use App\Modules\Templates\Domain\Template;
use App\Modules\Templates\Domain\TemplateLifecycle;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class DatabaseTemplateRepository
{
    public function __construct(
        private DatabaseManager $database,
        private CanonicalJsonHasher $hasher,
    ) {}

    public function createTemplate(Template $template): Template
    {
        return $this->database->connection()->transaction(function () use ($template): Template {
            $existing = $this->database->connection()->table('content_templates')
                ->where('id', $template->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                if ((string) $existing->workspace_id !== $template->workspaceId) {
                    throw new AuthorizationException('Template access denied.');
                }

                $stored = $this->hydrateTemplate($existing);
                $this->assertTemplateReplay($stored, $template);

                return $stored;
            }

            try {
                $this->database->connection()->table('content_templates')->insert($this->templatePayload($template));
            } catch (QueryException $exception) {
                $winner = $this->database->connection()->table('content_templates')->where('id', $template->id)->first();

                if ($winner instanceof stdClass) {
                    if ((string) $winner->workspace_id !== $template->workspaceId) {
                        throw new AuthorizationException('Template access denied.', previous: $exception);
                    }

                    $stored = $this->hydrateTemplate($winner);
                    $this->assertTemplateReplay($stored, $template);

                    return $stored;
                }

                throw $exception;
            }

            return $template;
        });
    }

    public function updateTemplate(Template $template, ?DateTimeImmutable $expectedUpdatedAt): Template
    {
        return $this->database->connection()->transaction(function () use ($template, $expectedUpdatedAt): Template {
            $row = $this->database->connection()->table('content_templates')
                ->where('workspace_id', $template->workspaceId)
                ->where('id', $template->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignOwnerIdExists('content_templates', $template->workspaceId, $template->id, 'Template');
                throw new InvalidArgumentException('Template does not exist in this workspace.');
            }

            $stored = $this->hydrateTemplate($row);
            $this->assertTemplateIdentity($stored, $template);

            if (! self::sameMoment($stored->updatedAt, $expectedUpdatedAt)) {
                throw new InvalidArgumentException('Template optimistic concurrency conflict.');
            }

            if ($template->updatedAt === null || $template->updatedAt <= ($stored->updatedAt ?? $stored->createdAt)) {
                throw new InvalidArgumentException('Template update must advance updatedAt.');
            }

            $updated = $this->database->connection()->table('content_templates')
                ->where('workspace_id', $template->workspaceId)
                ->where('id', $template->id)
                ->where(function ($query) use ($expectedUpdatedAt): void {
                    $expectedUpdatedAt === null
                        ? $query->whereNull('updated_at')
                        : $query->where('updated_at', $expectedUpdatedAt);
                })
                ->update([
                    'lifecycle' => $template->lifecycle->value,
                    'updated_at' => $template->updatedAt,
                ]);

            if ($updated !== 1) {
                throw new InvalidArgumentException('Template optimistic concurrency conflict.');
            }

            return $template;
        });
    }

    public function createComponent(ReusableComponent $component): ReusableComponent
    {
        return $this->database->connection()->transaction(function () use ($component): ReusableComponent {
            $existing = $this->database->connection()->table('reusable_components')
                ->where('id', $component->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                if ((string) $existing->workspace_id !== $component->workspaceId) {
                    throw new AuthorizationException('Reusable component access denied.');
                }

                $stored = $this->hydrateComponent($existing);
                $this->assertComponentReplay($stored, $component);

                return $stored;
            }

            try {
                $this->database->connection()->table('reusable_components')->insert($this->componentPayload($component));
            } catch (QueryException $exception) {
                $winner = $this->database->connection()->table('reusable_components')->where('id', $component->id)->first();

                if ($winner instanceof stdClass) {
                    if ((string) $winner->workspace_id !== $component->workspaceId) {
                        throw new AuthorizationException('Reusable component access denied.', previous: $exception);
                    }

                    $stored = $this->hydrateComponent($winner);
                    $this->assertComponentReplay($stored, $component);

                    return $stored;
                }

                throw $exception;
            }

            return $component;
        });
    }

    public function updateComponent(ReusableComponent $component, ?DateTimeImmutable $expectedUpdatedAt): ReusableComponent
    {
        return $this->database->connection()->transaction(function () use ($component, $expectedUpdatedAt): ReusableComponent {
            $row = $this->database->connection()->table('reusable_components')
                ->where('workspace_id', $component->workspaceId)
                ->where('id', $component->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignOwnerIdExists('reusable_components', $component->workspaceId, $component->id, 'Reusable component');
                throw new InvalidArgumentException('Reusable component does not exist in this workspace.');
            }

            $stored = $this->hydrateComponent($row);
            $this->assertComponentIdentity($stored, $component);

            if (! self::sameMoment($stored->updatedAt, $expectedUpdatedAt)) {
                throw new InvalidArgumentException('Reusable component optimistic concurrency conflict.');
            }

            if ($component->updatedAt === null || $component->updatedAt <= ($stored->updatedAt ?? $stored->createdAt)) {
                throw new InvalidArgumentException('Reusable component update must advance updatedAt.');
            }

            $updated = $this->database->connection()->table('reusable_components')
                ->where('workspace_id', $component->workspaceId)
                ->where('id', $component->id)
                ->where(function ($query) use ($expectedUpdatedAt): void {
                    $expectedUpdatedAt === null
                        ? $query->whereNull('updated_at')
                        : $query->where('updated_at', $expectedUpdatedAt);
                })
                ->update([
                    'lifecycle' => $component->lifecycle->value,
                    'updated_at' => $component->updatedAt,
                ]);

            if ($updated !== 1) {
                throw new InvalidArgumentException('Reusable component optimistic concurrency conflict.');
            }

            return $component;
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $variableSchema
     * @param  array<string, mixed>  $localizationSchema
     */
    public function appendVersion(
        VersionedDefinition $version,
        array $definition,
        array $variableSchema = [],
        array $localizationSchema = [],
    ): VersionedDefinition {
        return $this->database->connection()->transaction(function () use ($version, $definition, $variableSchema, $localizationSchema): VersionedDefinition {
            $this->assertCanonicalDefinition($definition);
            $this->assertOwnerScope($version);

            [$table] = $this->versionStorage($version->kind);
            $existing = $this->database->connection()->table($table)
                ->where('workspace_id', $version->workspaceId)
                ->where('idempotency_key', $version->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateDefinition($existing, $version->kind);
                $this->assertDefinitionReplay($stored, $version);
                $this->assertPayloadReplay($existing, $definition, $variableSchema, $localizationSchema);

                return $stored;
            }

            $this->assertParentLineage($version);
            $this->assertGlobalVersionIdAvailable($version);
            $this->assertDependenciesAndCycles($version);

            [$table, $ownerColumn] = $this->versionStorage($version->kind);

            try {
                $this->database->connection()->table($table)->insert([
                    'id' => $version->id,
                    'workspace_id' => $version->workspaceId,
                    $ownerColumn => $version->ownerId,
                    'parent_version_id' => $version->parentVersionId,
                    'version_number' => $version->versionNumber,
                    'schema_version' => $version->schemaVersion,
                    'status' => $version->status->value,
                    'definition' => $this->hasher->encode($definition),
                    'variable_schema' => $this->hasher->encode($variableSchema),
                    'localization_schema' => $this->hasher->encode($localizationSchema),
                    'dependencies' => $this->hasher->encode($this->dependencyPayload($version->dependencies)),
                    'idempotency_key' => $version->idempotencyKey,
                    'created_by_actor_id' => $version->createdByActorId,
                    'created_at' => $version->createdAt,
                ]);
            } catch (QueryException $exception) {
                $winner = $this->database->connection()->table($table)
                    ->where('workspace_id', $version->workspaceId)
                    ->where('idempotency_key', $version->idempotencyKey)
                    ->first();

                if ($winner instanceof stdClass) {
                    $stored = $this->hydrateDefinition($winner, $version->kind);
                    $this->assertDefinitionReplay($stored, $version);
                    $this->assertPayloadReplay($winner, $definition, $variableSchema, $localizationSchema);

                    return $stored;
                }

                throw $exception;
            }

            return $version;
        });
    }

    public function findVersion(string $workspaceId, DefinitionKind $kind, string $versionId): ?VersionedDefinition
    {
        [$table] = $this->versionStorage($kind);
        $row = $this->database->connection()->table($table)
            ->where('workspace_id', $workspaceId)
            ->where('id', $versionId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateDefinition($row, $kind);
        }

        if ($this->database->connection()->table($table)
            ->where('id', $versionId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Template/component version access denied.');
        }

        return null;
    }

    private function assertOwnerScope(VersionedDefinition $version): void
    {
        [$table] = $this->ownerStorage($version->kind);
        if ($this->database->connection()->table($table)
            ->where('workspace_id', $version->workspaceId)
            ->where('id', $version->ownerId)
            ->exists()) {
            return;
        }

        $this->denyIfForeignOwnerIdExists($table, $version->workspaceId, $version->ownerId, 'Template/component owner');
        throw new InvalidArgumentException('Template/component owner does not exist in this workspace.');
    }

    private function assertParentLineage(VersionedDefinition $version): void
    {
        if ($version->parentVersionId === null) {
            if ($version->versionNumber !== 1) {
                throw new InvalidArgumentException('Initial template/component version must use version number 1.');
            }

            return;
        }

        [$table, $ownerColumn] = $this->versionStorage($version->kind);
        $parent = $this->database->connection()->table($table)
            ->where('workspace_id', $version->workspaceId)
            ->where('id', $version->parentVersionId)
            ->first();

        if (! $parent instanceof stdClass) {
            if ($this->database->connection()->table($table)
                ->where('id', $version->parentVersionId)
                ->where('workspace_id', '!=', $version->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Template/component parent version access denied.');
            }

            throw new InvalidArgumentException('Template/component parent version does not exist in this workspace.');
        }

        if ((string) $parent->{$ownerColumn} !== $version->ownerId) {
            throw new InvalidArgumentException('Template/component parent belongs to a different owner.');
        }

        if ((int) $parent->version_number + 1 !== $version->versionNumber) {
            throw new InvalidArgumentException('Template/component lineage must advance exactly one version.');
        }

        if (new DateTimeImmutable((string) $parent->created_at) > $version->createdAt) {
            throw new InvalidArgumentException('Template/component version cannot precede its parent.');
        }
    }

    private function assertGlobalVersionIdAvailable(VersionedDefinition $version): void
    {
        foreach ([DefinitionKind::Template, DefinitionKind::Component] as $kind) {
            [$table] = $this->versionStorage($kind);
            $row = $this->database->connection()->table($table)->where('id', $version->id)->first();

            if (! $row instanceof stdClass) {
                continue;
            }

            if ((string) $row->workspace_id !== $version->workspaceId) {
                throw new AuthorizationException('Template/component version access denied.');
            }

            if ($kind !== $version->kind) {
                throw new InvalidArgumentException('Template/component version id is already used by another definition kind.');
            }

            throw new InvalidArgumentException('Template/component version id already exists with a different idempotency key.');
        }
    }

    private function assertDependenciesAndCycles(VersionedDefinition $candidate): void
    {
        /** @var array<string, list<string>> $edges */
        $edges = [];
        /** @var array<string, true> $known */
        $known = [];

        foreach ([DefinitionKind::Template, DefinitionKind::Component] as $kind) {
            [$table] = $this->versionStorage($kind);
            $rows = $this->database->connection()->table($table)
                ->where('workspace_id', $candidate->workspaceId)
                ->get(['id', 'dependencies']);

            foreach ($rows as $row) {
                $node = $this->nodeKey($kind, (string) $row->id);
                $known[$node] = true;
                $edges[$node] = [];

                foreach ($this->hydrateDependencies($row->dependencies) as $dependency) {
                    if ($dependency->workspaceId !== $candidate->workspaceId) {
                        throw new InvalidArgumentException('Stored template/component dependency crosses workspace boundary.');
                    }

                    $edges[$node][] = $this->dependencyNodeKey($dependency);
                }
            }
        }

        $candidateNode = $this->nodeKey($candidate->kind, $candidate->id);
        $known[$candidateNode] = true;
        $edges[$candidateNode] = [];

        foreach ($candidate->dependencies as $dependency) {
            $target = $this->dependencyNodeKey($dependency);
            $edges[$candidateNode][] = $target;

            if (isset($known[$target])) {
                continue;
            }

            [$table] = $this->dependencyStorage($dependency->kind);
            $row = $this->database->connection()->table($table)->where('id', $dependency->versionId)->first();

            if ($row instanceof stdClass && (string) $row->workspace_id !== $candidate->workspaceId) {
                throw new AuthorizationException('Template/component dependency target access denied.');
            }

            throw new InvalidArgumentException('Template/component dependency target does not exist in this workspace.');
        }

        foreach ($edges as $targets) {
            foreach ($targets as $target) {
                if (! isset($known[$target])) {
                    throw new InvalidArgumentException('Stored template/component dependency target is unresolved.');
                }
            }
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($known) as $node) {
            $this->visitDependencyNode($node, $edges, $visiting, $visited);
        }
    }

    /**
     * @param  array<string, list<string>>  $edges
     * @param  array<string, true>  $visiting
     * @param  array<string, true>  $visited
     */
    private function visitDependencyNode(string $node, array $edges, array &$visiting, array &$visited): void
    {
        if (isset($visited[$node])) {
            return;
        }

        if (isset($visiting[$node])) {
            throw new InvalidArgumentException("Template/component dependency cycle detected at {$node}.");
        }

        $visiting[$node] = true;
        foreach ($edges[$node] ?? [] as $target) {
            $this->visitDependencyNode($target, $edges, $visiting, $visited);
        }
        unset($visiting[$node]);
        $visited[$node] = true;
    }

    /** @param  list<DependencyReference>  $dependencies */
    private function dependencyPayload(array $dependencies): array
    {
        $payload = array_map(
            static fn (DependencyReference $dependency): array => [
                'workspace_id' => $dependency->workspaceId,
                'kind' => $dependency->kind->value,
                'version_id' => $dependency->versionId,
            ],
            $dependencies,
        );

        usort(
            $payload,
            static fn (array $left, array $right): int => [$left['kind'], $left['version_id']] <=> [$right['kind'], $right['version_id']],
        );

        return $payload;
    }

    /** @return list<DependencyReference> */
    private function hydrateDependencies(mixed $payload): array
    {
        $decoded = $this->decodeArray($payload, 'definition dependencies');
        $dependencies = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException('Stored definition dependency must be an object.');
            }

            $workspaceId = $item['workspace_id'] ?? null;
            $kind = $item['kind'] ?? null;
            $versionId = $item['version_id'] ?? null;

            if (! is_string($workspaceId) || ! is_string($kind) || ! is_string($versionId)) {
                throw new UnexpectedValueException('Stored definition dependency is malformed.');
            }

            $dependencies[] = new DependencyReference(
                workspaceId: $workspaceId,
                kind: DependencyKind::from($kind),
                versionId: $versionId,
            );
        }

        return $dependencies;
    }

    private function hydrateDefinition(stdClass $row, DefinitionKind $kind): VersionedDefinition
    {
        [$table, $ownerColumn] = $this->versionStorage($kind);

        return new VersionedDefinition(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            kind: $kind,
            ownerId: (string) $row->{$ownerColumn},
            parentVersionId: $row->parent_version_id === null ? null : (string) $row->parent_version_id,
            versionNumber: (int) $row->version_number,
            schemaVersion: (int) $row->schema_version,
            status: VersionStatus::from((string) $row->status),
            dependencies: $this->hydrateDependencies($row->dependencies),
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $variableSchema
     * @param  array<string, mixed>  $localizationSchema
     */
    private function assertPayloadReplay(stdClass $row, array $definition, array $variableSchema, array $localizationSchema): void
    {
        if (
            $this->hasher->hash($this->decodeArray($row->definition, 'definition')) !== $this->hasher->hash($definition)
            || $this->hasher->hash($this->decodeArray($row->variable_schema, 'variable_schema')) !== $this->hasher->hash($variableSchema)
            || $this->hasher->hash($this->decodeArray($row->localization_schema, 'localization_schema')) !== $this->hasher->hash($localizationSchema)
        ) {
            throw new InvalidArgumentException('Template/component idempotency key conflicts with different canonical payload.');
        }
    }

    private function assertDefinitionReplay(VersionedDefinition $stored, VersionedDefinition $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->kind !== $candidate->kind
            || $stored->ownerId !== $candidate->ownerId
            || $stored->parentVersionId !== $candidate->parentVersionId
            || $stored->versionNumber !== $candidate->versionNumber
            || $stored->schemaVersion !== $candidate->schemaVersion
            || $stored->status !== $candidate->status
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $this->dependencyPayload($stored->dependencies) !== $this->dependencyPayload($candidate->dependencies)
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Template/component idempotency key conflicts with different immutable version.');
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function assertCanonicalDefinition(array $definition): void
    {
        $this->hasher->encode($definition);
        $this->assertForbiddenCanonicalKeys($definition, 'definition');
    }

    /** @param  array<string, mixed>  $payload */
    private function assertForbiddenCanonicalKeys(array $payload, string $path): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/^(?:raw_html|rendered_html|provider_html|provider_payload|mime_payload|javascript|script|executable)$/i', $key)) {
                throw new InvalidArgumentException("Provider/render payload is forbidden in canonical template/component state: {$path}.{$key}");
            }

            if (is_array($value)) {
                $this->assertForbiddenCanonicalKeys($value, $path.'.'.(string) $key);
            }
        }
    }

    private function assertTemplateReplay(Template $stored, Template $candidate): void
    {
        $this->assertTemplateIdentity($stored, $candidate);

        if (
            $stored->lifecycle !== $candidate->lifecycle
            || ! self::sameMoment($stored->updatedAt, $candidate->updatedAt)
        ) {
            throw new InvalidArgumentException('Template ID conflicts with different state.');
        }
    }

    private function assertTemplateIdentity(Template $stored, Template $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->name !== $candidate->name
            || $stored->createdByActorId !== $candidate->createdByActorId
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Template immutable identity conflicts with stored state.');
        }
    }

    private function assertComponentReplay(ReusableComponent $stored, ReusableComponent $candidate): void
    {
        $this->assertComponentIdentity($stored, $candidate);

        if (
            $stored->lifecycle !== $candidate->lifecycle
            || ! self::sameMoment($stored->updatedAt, $candidate->updatedAt)
        ) {
            throw new InvalidArgumentException('Reusable component ID conflicts with different state.');
        }
    }

    private function assertComponentIdentity(ReusableComponent $stored, ReusableComponent $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->name !== $candidate->name
            || $stored->createdByActorId !== $candidate->createdByActorId
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Reusable component immutable identity conflicts with stored state.');
        }
    }

    /** @return array<string, mixed> */
    private function templatePayload(Template $template): array
    {
        return [
            'id' => $template->id,
            'workspace_id' => $template->workspaceId,
            'name' => $template->name,
            'lifecycle' => $template->lifecycle->value,
            'created_by_actor_id' => $template->createdByActorId,
            'created_at' => $template->createdAt,
            'updated_at' => $template->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function componentPayload(ReusableComponent $component): array
    {
        return [
            'id' => $component->id,
            'workspace_id' => $component->workspaceId,
            'name' => $component->name,
            'lifecycle' => $component->lifecycle->value,
            'created_by_actor_id' => $component->createdByActorId,
            'created_at' => $component->createdAt,
            'updated_at' => $component->updatedAt,
        ];
    }

    private function hydrateTemplate(stdClass $row): Template
    {
        return new Template(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            name: (string) $row->name,
            lifecycle: TemplateLifecycle::from((string) $row->lifecycle),
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: $row->updated_at === null ? null : new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function hydrateComponent(stdClass $row): ReusableComponent
    {
        return new ReusableComponent(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            name: (string) $row->name,
            lifecycle: ComponentLifecycle::from((string) $row->lifecycle),
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: $row->updated_at === null ? null : new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function denyIfForeignOwnerIdExists(string $table, string $workspaceId, string $id, string $label): void
    {
        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException("{$label} access denied.");
        }
    }

    /** @return array{string, string} */
    private function versionStorage(DefinitionKind $kind): array
    {
        return match ($kind) {
            DefinitionKind::Template => ['content_template_versions', 'template_id'],
            DefinitionKind::Component => ['reusable_component_versions', 'component_id'],
        };
    }

    /** @return array{string, string} */
    private function ownerStorage(DefinitionKind $kind): array
    {
        return match ($kind) {
            DefinitionKind::Template => ['content_templates', 'id'],
            DefinitionKind::Component => ['reusable_components', 'id'],
        };
    }

    private function dependencyStorage(DependencyKind $kind): array
    {
        return match ($kind) {
            DependencyKind::TemplateVersion => ['content_template_versions'],
            DependencyKind::ComponentVersion => ['reusable_component_versions'],
        };
    }

    private function nodeKey(DefinitionKind $kind, string $versionId): string
    {
        return match ($kind) {
            DefinitionKind::Template => DependencyKind::TemplateVersion->value.':'.$versionId,
            DefinitionKind::Component => DependencyKind::ComponentVersion->value.':'.$versionId,
        };
    }

    private function dependencyNodeKey(DependencyReference $dependency): string
    {
        return $dependency->kind->value.':'.$dependency->versionId;
    }

    /** @return array<string, mixed> */
    private function decodeArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException("Stored {$field} must be JSON.");
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("Stored {$field} contains invalid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Stored {$field} must decode to an array.");
        }

        return $decoded;
    }

    private static function sameMoment(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left == $right;
    }
}
