<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\CompiledSegment;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

final readonly class DeterministicSegmentCompiler
{
    private DatabaseManager $database;

    private SegmentValidator $validator;

    private SegmentFieldRegistry $fields;

    public function __construct(
        DatabaseManager $database,
        SegmentValidator $validator,
        SegmentFieldRegistry $fields,
    ) {
        $this->database = $database;
        $this->validator = $validator;
        $this->fields = $fields;
    }

    /** @param array<string, mixed> $definition */
    public function compile(array $definition, TenantContext $scope, DateTimeImmutable $evaluationInstant): CompiledSegment
    {
        $ast = $this->validator->normalize($definition);
        $estimatedCost = $this->estimateCost($ast['root']);
        if ($estimatedCost > (int) config('segmentation.max_cost', 100)) {
            throw new SegmentDefinitionException('cost_limit_exceeded', '$.root');
        }
        $at = $evaluationInstant->setTimezone(new DateTimeZone('UTC'));
        $evaluatedAt = $at->format('Y-m-d H:i:s');
        $query = $this->database->connection()->table('contacts as c')
            ->select('c.id')
            ->where('c.workspace_id', $scope->workspaceId)
            ->distinct();

        if ($scope->brandId !== null) {
            $query->where('c.brand_id', $scope->brandId);
        }
        $this->apply($query, $ast['root'], 'and', $scope, $at);

        $definitionHash = $this->validator->hash($ast);
        $fingerprint = hash('sha256', implode('|', [$scope->workspaceId, $scope->brandId ?? '', $definitionHash, $evaluatedAt]));

        return new CompiledSegment($query, $definitionHash, $fingerprint, $evaluatedAt, $estimatedCost, (int) config('segmentation.query_timeout_ms', 3000));
    }

    /** @param array<string, mixed> $node */
    private function apply(Builder $query, array $node, string $boolean, TenantContext $scope, DateTimeImmutable $at): void
    {
        if ($node['type'] === 'group') {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $nested) use ($node, $scope, $at): void {
                foreach ($node['children'] as $index => $child) {
                    $childBoolean = $index === 0 || $node['operator'] === 'all' ? 'and' : 'or';
                    $this->apply($nested, $child, $childBoolean, $scope, $at);
                }
            });

            return;
        }
        if ($node['type'] === 'not') {
            $method = $boolean === 'or' ? 'orWhereNot' : 'whereNot';
            $query->{$method}(function (Builder $nested) use ($node, $scope, $at): void {
                $this->apply($nested, $node['child'], 'and', $scope, $at);
            });

            return;
        }
        if ($node['type'] === 'attribute') {
            $this->attribute($query, $node, $boolean, $scope);

            return;
        }
        if ($node['type'] === 'membership') {
            $this->membership($query, $node, $boolean, $scope);

            return;
        }
        if ($node['type'] === 'event') {
            $this->event($query, $node, $boolean, $scope, $at);

            return;
        }
        throw new SegmentDefinitionException('unknown_node_type', '$.root');
    }

    private function attribute(Builder $query, array $node, string $boolean, TenantContext $scope): void
    {
        $field = $this->fields->get($node['field']);
        if ($field === null) {
            throw new SegmentDefinitionException('unknown_or_non_targetable_field', '$.root.field');
        }
        $sqlOperator = match ($node['operator']) {
            'equals' => '=',
            'not_equals' => '!=',
            'before' => '<',
            'after' => '>',
            'on_or_before' => '<=',
            'on_or_after' => '>=',
            default => null,
        };
        if (in_array($node['operator'], ['is_set', 'is_not_set'], true)) {
            $this->attributeExists($query, $node['field'], $node['operator'] === 'is_set', $boolean, $scope);

            return;
        }
        if ($sqlOperator === null) {
            throw new SegmentDefinitionException('operator_not_allowed_for_field', '$.root.operator');
        }
        if ($field['table'] === 'contacts') {
            $column = 'c.'.$field['column'];
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $value = $field['type'] === 'timestamp'
                ? (new DateTimeImmutable($node['value']))->format('Y-m-d H:i:s')
                : $node['value'];
            $query->{$method}($column, $sqlOperator, $value);

            return;
        }
        $method = $boolean === 'or' ? 'orWhereExists' : 'whereExists';
        $query->{$method}(function (Builder $company) use ($field, $node, $sqlOperator, $scope): void {
            $company->select('cp.id')
                ->from('companies as cp')
                ->whereColumn('cp.id', 'c.company_id')
                ->whereColumn('cp.workspace_id', 'c.workspace_id')
                ->where('cp.workspace_id', $scope->workspaceId)
                ->where('cp.'.$field['column'], $sqlOperator, $node['value']);
        });
    }

    private function attributeExists(Builder $query, string $fieldId, bool $isSet, string $boolean, TenantContext $scope): void
    {
        $field = $this->fields->get($fieldId);
        if ($field === null) {
            throw new SegmentDefinitionException('unknown_or_non_targetable_field', '$.root.field');
        }
        if ($field['table'] === 'contacts') {
            $method = ($boolean === 'or' ? 'orWhere' : 'where').($isSet ? 'NotNull' : 'Null');
            $query->{$method}('c.'.$field['column']);

            return;
        }
        $method = $isSet
            ? ($boolean === 'or' ? 'orWhereExists' : 'whereExists')
            : ($boolean === 'or' ? 'orWhereNotExists' : 'whereNotExists');
        $query->{$method}(function (Builder $company) use ($field, $scope): void {
            $company->select('cp.id')
                ->from('companies as cp')
                ->whereColumn('cp.id', 'c.company_id')
                ->whereColumn('cp.workspace_id', 'c.workspace_id')
                ->where('cp.workspace_id', $scope->workspaceId)
                ->whereNotNull('cp.'.$field['column']);
        });
    }

    private function membership(Builder $query, array $node, string $boolean, TenantContext $scope): void
    {
        $kind = $node['kind'];
        $definitionTable = $kind === 'list' ? 'contact_lists' : 'tags';
        $membershipTable = $kind === 'list' ? 'contact_list_memberships' : 'contact_tag_assignments';
        $referenceColumn = $kind === 'list' ? 'list_id' : 'tag_id';
        if ($this->database->table($definitionTable)
            ->where('workspace_id', $scope->workspaceId)
            ->where('id', $node['id'])
            ->exists() === false) {
            throw new SegmentDefinitionException('unknown_or_foreign_membership', '$.root.id');
        }
        $method = $node['operator'] === 'in'
            ? ($boolean === 'or' ? 'orWhereExists' : 'whereExists')
            : ($boolean === 'or' ? 'orWhereNotExists' : 'whereNotExists');
        $query->{$method}(function (Builder $membership) use ($membershipTable, $referenceColumn, $node, $scope): void {
            $membership->select('m.contact_id')
                ->from($membershipTable.' as m')
                ->whereColumn('m.contact_id', 'c.id')
                ->whereColumn('m.workspace_id', 'c.workspace_id')
                ->where('m.workspace_id', $scope->workspaceId)
                ->where('m.'.$referenceColumn, $node['id']);
        });
    }

    private function event(Builder $query, array $node, string $boolean, TenantContext $scope, DateTimeImmutable $at): void
    {
        if ($this->database->table('event_types')
            ->where('workspace_id', $scope->workspaceId)
            ->where('canonical_name', $node['name'])
            ->exists() === false) {
            throw new SegmentDefinitionException('unknown_or_foreign_event', '$.root.name');
        }
        [$from, $to] = $this->window($node['window'], $at);
        $mode = $node['mode'];
        $method = $mode === 'not_exists'
            ? ($boolean === 'or' ? 'orWhereNotExists' : 'whereNotExists')
            : ($boolean === 'or' ? 'orWhereExists' : 'whereExists');
        $query->{$method}(function (Builder $events) use ($node, $scope, $from, $to, $mode): void {
            $events->select('ce.id')
                ->from('customer_events as ce')
                ->join('event_types as et', function ($join): void {
                    $join->on('et.id', '=', 'ce.event_type_id')->on('et.workspace_id', '=', 'ce.workspace_id');
                })
                ->whereColumn('ce.workspace_id', 'c.workspace_id')
                ->whereColumn('ce.contact_id', 'c.id')
                ->where('ce.workspace_id', $scope->workspaceId)
                ->where('et.canonical_name', $node['name'])
                ->where('ce.occurred_at', '>=', $from)
                ->where('ce.occurred_at', '<', $to);
            if ($mode === 'count') {
                $events->select('ce.contact_id')->groupBy('ce.contact_id')->havingRaw('COUNT(*) >= ?', [$node['minimum']]);
            }
            if ($mode === 'first' || $mode === 'last') {
                $direction = $mode === 'first' ? '<' : '>';
                $events->whereNotExists(function (Builder $other) use ($scope, $node, $direction, $from, $to): void {
                    $other->select('ce2.id')
                        ->from('customer_events as ce2')
                        ->join('event_types as et2', function ($join): void {
                            $join->on('et2.id', '=', 'ce2.event_type_id')->on('et2.workspace_id', '=', 'ce2.workspace_id');
                        })
                        ->whereColumn('ce2.workspace_id', 'c.workspace_id')
                        ->whereColumn('ce2.contact_id', 'c.id')
                        ->where('ce2.workspace_id', $scope->workspaceId)
                        ->where('et2.canonical_name', $node['name'])
                        ->where('ce2.occurred_at', '>=', $from)
                        ->where('ce2.occurred_at', '<', $to)
                        ->whereColumn('ce2.occurred_at', $direction, 'ce.occurred_at');
                });
            }
        });
    }

    /** @param array<string, mixed> $node */
    private function estimateCost(array $node): int
    {
        if ($node['type'] === 'group') {
            return 1 + array_sum(array_map(fn (array $child): int => $this->estimateCost($child), $node['children']));
        }
        if ($node['type'] === 'not') {
            return 1 + $this->estimateCost($node['child']);
        }
        if ($node['type'] === 'membership') {
            return 3;
        }
        if ($node['type'] === 'event') {
            return match ($node['mode']) {
                'exists', 'not_exists' => 5,
                'count' => 8,
                'first', 'last' => 10,
                default => throw new SegmentDefinitionException('invalid_event_mode', '$.root.mode'),
            };
        }

        return 1;
    }

    private function window(array $window, DateTimeImmutable $at): array
    {
        if ($window['kind'] === 'relative') {
            return [
                $at->modify('-'.$window['days'].' days')->format('Y-m-d H:i:s'),
                $at->format('Y-m-d H:i:s'),
            ];
        }

        return [
            (new DateTimeImmutable($window['from']))->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($window['to']))->format('Y-m-d H:i:s'),
        ];
    }
}
