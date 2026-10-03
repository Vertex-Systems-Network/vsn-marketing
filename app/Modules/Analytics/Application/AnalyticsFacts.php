<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\AnalyticsAccess;
use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Analytics\Domain\BehaviorMetrics;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Analytics\Domain\RevenueDefinition;
use App\Modules\Analytics\Domain\RevenueExperimentVerifier;
use App\Modules\Analytics\Domain\RevenueMetrics;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class AnalyticsFacts
{
    public const MAX_FACTS = 1000;

    public function __construct(
        private DatabaseManager $database,
        private AnalyticsAccess $access,
        private AnalyticsPrivacy $privacy,
        private Clock $clock,
        private AuditRecorder $audit,
        private string $subjectSecret,
        private ?RevenueExperimentVerifier $revenueExperiments = null,
    ) {
        if (strlen($subjectSecret) < 32) {
            throw new InvalidArgumentException('Analytics requires a stable protected pseudonym key.');
        }
    }

    /** Existing canonical event only; callers cannot supply a new envelope or asserted consent. */
    public function project(TenantContext $actor, string $eventId): string
    {
        $days = $this->permit($actor);

        return $this->database->transaction(function () use ($actor, $eventId, $days): string {
            // Serialize projection/invalidation in this workspace, including competing source-key admission.
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $event = $this->database->table('customer_events')
                ->join('event_types', 'event_types.id', '=', 'customer_events.event_type_id')
                ->where('customer_events.workspace_id', $actor->workspaceId)
                ->where('event_types.workspace_id', $actor->workspaceId)
                ->where('customer_events.id', $eventId)->select('customer_events.*', 'event_types.canonical_name')->first();
            if (! $event instanceof stdClass || ($actor->brandId !== null && $event->brand_id !== $actor->brandId)) {
                throw new AuthorizationException('Analytics event scope denied.');
            }
            $scope = new TenantContext($actor->organizationId, $actor->workspaceId, $event->brand_id, $actor->actorId);
            $occurred = new DateTimeImmutable($event->occurred_at);
            $received = new DateTimeImmutable($event->received_at);
            $now = $this->clock->now();
            $expires = $occurred->modify('+'.$days.' days');
            $scopeKey = $this->scopeKey($scope);
            $subjectKey = $this->subjectKey($scope, $event->contact_id);
            if ($event->schema_version !== 1 || ! in_array($event->canonical_name, MetricDefinition::EVENTS, true)
                || $event->source === '' || $event->source_event_id === ''
                || $occurred > $received || $received > $now || $expires <= $now
                || ! $this->contactMatches($scope, $event->contact_id)
                || ! $this->privacy->permits($scope, $event->contact_id, $occurred)
                || $this->invalidated($scope, $subjectKey)) {
                throw new InvalidArgumentException('Analytics event denied by schema, time, identity, purpose or retention.');
            }
            $sourceKey = hash('sha256', json_encode([$actor->workspaceId, $scopeKey, $event->source,
                $event->source_event_id ?? 'canonical:'.$event->id], JSON_THROW_ON_ERROR));
            $hash = $this->envelopeHash($event);
            $existing = $this->database->table('analytics_facts')->where('event_id', $eventId)
                ->orWhere('source_key', $sourceKey)->first();
            if ($existing instanceof stdClass) {
                if ($existing->workspace_id !== $actor->workspaceId || $existing->scope_key !== $scopeKey) {
                    throw new AuthorizationException('Analytics replay scope denied.');
                }
                if ($existing->event_id === $eventId && hash_equals($existing->envelope_hash, $hash)
                    && hash_equals($existing->subject_key, $subjectKey)) {
                    return 'replayed';
                }
                $this->database->table('analytics_conflicts')->insertOrIgnore([
                    'id' => hash('sha256', $sourceKey.$hash), 'workspace_id' => $actor->workspaceId,
                    'scope_key' => $scopeKey, 'source_key' => $sourceKey, 'candidate_hash' => $hash, 'created_at' => $now,
                ]);
                $this->record($scope, 'analytics.source_conflict', ['source_key' => $sourceKey, 'candidate_hash' => $hash]);

                return 'conflict';
            }
            $this->database->table('analytics_facts')->insert([
                'id' => (string) Str::uuid(), 'workspace_id' => $actor->workspaceId, 'brand_id' => $event->brand_id,
                'event_id' => $eventId, 'scope_key' => $scopeKey, 'subject_key' => $subjectKey,
                'source_key' => $sourceKey, 'source' => $event->source, 'event_type' => $event->canonical_name,
                'schema_version' => 1, 'envelope_hash' => $hash, 'occurred_at' => $occurred,
                'received_at' => $received, 'expires_at' => $expires, 'created_at' => $now,
            ]);
            $this->record($scope, 'analytics.fact_projected', ['event_id' => $eventId, 'envelope_hash' => $hash]);

            return 'admitted';
        }, 3);
    }

    public function snapshot(TenantContext $actor, MetricDefinition $definition, DateTimeImmutable $start,
        DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        $this->permit($actor);
        if ($start->getOffset() !== 0 || $end->getOffset() !== 0 || $cutoff->getOffset() !== 0
            || $start >= $end || $end > $cutoff || $cutoff > $this->clock->now()
            || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400) {
            throw new InvalidArgumentException('Analytics requires bounded half-open UTC window and trusted cutoff.');
        }

        return $this->database->transaction(function () use ($actor, $definition, $start, $end, $cutoff): array {
            $rows = $this->factsQuery($actor)->where('analytics_facts.event_type', $definition->eventType)
                ->where('analytics_facts.occurred_at', '>=', $start)->where('analytics_facts.occurred_at', '<', $end)
                ->where('analytics_facts.received_at', '<=', $cutoff)
                ->where('analytics_facts.created_at', '<=', $cutoff)
                ->orderBy('analytics_facts.occurred_at')->orderBy('analytics_facts.event_id')
                ->limit(self::MAX_FACTS + 1)->get();
            if ($rows->count() > self::MAX_FACTS) {
                throw new RuntimeException('Analytics fact bound exceeded; no truncated total returned.');
            }
            $lineage = [];
            $subjects = [];
            $excluded = 0;
            $latest = null;
            foreach ($rows as $row) {
                if (! $this->readable($actor, $row)) {
                    $excluded++;

                    continue;
                }
                $lineage[$row->id] = $row->envelope_hash;
                $subjects[$row->subject_key] = true;
                if ($latest === null || $row->received_at > $latest) {
                    $latest = $row->received_at;
                }
            }
            ksort($lineage, SORT_STRING);
            $report = ['schema_version' => 1, 'workspace_id' => $actor->workspaceId, 'scope_key' => $this->scopeKey($actor),
                'definition' => $definition->toArray(), 'definition_hash' => $definition->fingerprint(),
                'start_utc' => $start->format(DATE_ATOM), 'end_utc' => $end->format(DATE_ATOM),
                'receipt_cutoff_utc' => $cutoff->format(DATE_ATOM), 'value' => $definition->unit === 'event' ? count($lineage) : count($subjects),
                'admitted_events' => count($lineage), 'unique_subjects' => count($subjects), 'excluded' => $excluded,
                'source_completeness' => 'unknown', 'latest_receipt_utc' => $latest,
                'sampling' => 'none', 'quality' => $excluded === 0 ? 'locally_admitted' : 'excluded_facts',
                'lineage' => $lineage, 'publication_authorized' => false];

            return $this->persistSnapshot($actor, $report);
        }, 3);
    }

    public function behaviorSnapshot(TenantContext $actor, BehaviorDefinition $definition,
        DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        $this->permit($actor);
        if ($start->getOffset() !== 0 || $end->getOffset() !== 0 || $cutoff->getOffset() !== 0
            || $start >= $end || $end > $cutoff || $cutoff > $this->clock->now()) {
            throw new InvalidArgumentException('Behavior requires bounded UTC entry and observation windows.');
        }
        $queryStart = $definition->kind === 'lifecycle'
            ? $start->modify('-'.($end->getTimestamp() - $start->getTimestamp()).' seconds') : $start;
        $horizon = match ($definition->kind) {
            'funnel' => $definition->conversionSeconds,
            'retention' => $definition->bins * $definition->binSeconds,
            default => 0,
        };
        $queryEnd = min($end->modify('+'.$horizon.' seconds'), $cutoff);
        if ($queryEnd->getTimestamp() - $queryStart->getTimestamp() > 31 * 86400) {
            throw new InvalidArgumentException('Behavior requires bounded UTC entry and observation windows.');
        }

        return $this->database->transaction(function () use ($actor, $definition, $start, $end, $cutoff, $queryStart, $queryEnd): array {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $rows = $this->factsQuery($actor)->whereIn('analytics_facts.event_type', $definition->events)
                ->where('analytics_facts.occurred_at', '>=', $queryStart)
                ->where('analytics_facts.occurred_at', $definition->kind === 'funnel' || $definition->kind === 'retention' ? '<=' : '<', $queryEnd)
                ->where('analytics_facts.received_at', '<=', $cutoff)->where('analytics_facts.created_at', '<=', $cutoff)
                ->orderBy('analytics_facts.occurred_at')->orderBy('analytics_facts.event_id')
                ->limit(self::MAX_FACTS + 1)->get();
            if ($rows->count() > self::MAX_FACTS) {
                throw new RuntimeException('Behavior observation bound exceeded; no truncated report.');
            }
            $observations = $lineage = $dimensions = [];
            $excluded = 0;
            $latest = null;
            foreach ($rows as $row) {
                if (! $this->readable($actor, $row)) {
                    $excluded++;

                    continue;
                }
                $dimension = $definition->kind === 'performance' ? $this->dimension($actor, $row, $definition->dimension) : null;
                $observations[] = ['event_id' => $row->event_id, 'subject_key' => $row->subject_key,
                    'event_type' => $row->event_type, 'occurred_at' => $row->occurred_at, 'dimension' => $dimension];
                $lineage[$row->id] = $row->envelope_hash;
                if ($definition->kind === 'performance') {
                    $dimensions[$row->id] = $dimension;
                }
                $latest = $latest === null || $row->received_at > $latest ? $row->received_at : $latest;
            }
            ksort($lineage);
            ksort($dimensions);
            $report = ['schema_version' => 1, 'workspace_id' => $actor->workspaceId, 'scope_key' => $this->scopeKey($actor),
                'definition' => $definition->toArray(), 'definition_hash' => $definition->fingerprint(),
                'start_utc' => $start->format(DATE_ATOM), 'end_utc' => $end->format(DATE_ATOM),
                'observation_start_utc' => $queryStart->format(DATE_ATOM), 'observation_end_utc' => $queryEnd->format(DATE_ATOM),
                'receipt_cutoff_utc' => $cutoff->format(DATE_ATOM), 'source_completeness' => 'unknown',
                'latest_receipt_utc' => $latest, 'sampling' => 'none', 'admitted_events' => count($observations),
                'excluded' => $excluded, 'quality' => $excluded === 0 ? 'locally_admitted' : 'excluded_facts',
                'lineage' => $lineage, 'dimension_lineage' => $dimensions, 'publication_authorized' => false,
                'result' => (new BehaviorMetrics)->calculate($definition, $observations, $start, $end, $cutoff)];

            return $this->persistSnapshot($actor, $report);
        }, 3);
    }

    public function revenueSnapshot(TenantContext $actor, RevenueDefinition $definition,
        DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        $this->permit($actor);
        if ($start->getOffset() !== 0 || $end->getOffset() !== 0 || $cutoff->getOffset() !== 0
            || $start >= $end || $end > $cutoff || $cutoff > $this->clock->now()
            || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400) {
            throw new InvalidArgumentException('Revenue requires bounded UTC window and trusted cutoff.');
        }

        return $this->database->transaction(function () use ($actor, $definition, $start, $end, $cutoff): array {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            // Reconcile identities across ALL retained history before filtering purchases by report window.
            $rows = $this->factsQuery($actor)->whereIn('analytics_facts.event_type',
                array_unique(['order.completed', 'order.refunded', $definition->cohortEvent, ...RevenueDefinition::TOUCHES]))
                ->where('analytics_facts.received_at', '<=', $cutoff)->where('analytics_facts.created_at', '<=', $cutoff)
                ->orderBy('analytics_facts.occurred_at')->orderBy('analytics_facts.event_id')->limit(self::MAX_FACTS + 1)->get();
            if ($rows->count() > self::MAX_FACTS) {
                throw new RuntimeException('Revenue retained-history bound exceeded; no truncated ledger.');
            }
            $facts = $lineage = $experiments = [];
            $excluded = 0;
            foreach ($rows as $row) {
                if (! $this->readable($actor, $row)) {
                    $excluded++;

                    continue;
                }
                $facts[] = ['id' => $row->id, 'subject' => $row->subject_key, 'type' => $row->event_type,
                    'at' => (new DateTimeImmutable($row->occurred_at))->getTimestamp(),
                    'money' => $this->money($row), 'channel' => $this->dimension($actor, $row, 'channel')];
                $lineage[$row->id] = $row->envelope_hash;
                if ($row->event_type === 'order.completed') {
                    $experiments[$row->id] = $this->experimentReference($actor, $row);
                }
            }
            ksort($lineage);
            ksort($experiments);
            $report = ['schema_version' => 1, 'workspace_id' => $actor->workspaceId, 'scope_key' => $this->scopeKey($actor),
                'definition' => $definition->toArray(), 'definition_hash' => $definition->fingerprint(),
                'start_utc' => $start->format(DATE_ATOM), 'end_utc' => $end->format(DATE_ATOM),
                'receipt_cutoff_utc' => $cutoff->format(DATE_ATOM), 'source_completeness' => 'unknown',
                'history_completeness' => 'retained_admitted_only', 'sampling' => 'none', 'excluded' => $excluded,
                'lineage' => $lineage, 'experiment_lineage' => $experiments, 'publication_authorized' => false,
                'result' => (new RevenueMetrics)->calculate($definition, $facts, $start, $end, $cutoff)];

            return $this->persistSnapshot($actor, $report);
        }, 3);
    }

    private function money(stdClass $fact): ?array
    {
        if (! in_array($fact->event_type, ['order.completed', 'order.refunded'], true)) {
            return null;
        }
        $payload = json_decode($fact->payload, true, 32, JSON_THROW_ON_ERROR);
        $transaction = $payload['transaction_id'] ?? null;
        $refund = $payload['refund_id'] ?? null;
        $amount = $payload['amount_minor'] ?? null;
        $currency = $payload['currency'] ?? null;
        $exponent = $payload['currency_exponent'] ?? null;
        if (! is_string($transaction) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $transaction)
            || ! is_int($amount) || $amount < 1 || $amount > 1000000000000
            || ! is_string($currency) || ! array_key_exists($currency, RevenueDefinition::CURRENCIES)
            || $exponent !== RevenueDefinition::CURRENCIES[$currency]
            || ($fact->event_type === 'order.refunded' && (! is_string($refund) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $refund)))) {
            return null;
        }
        $purchase = hash_hmac('sha256', json_encode([$fact->scope_key, $fact->source, 'purchase', $transaction], JSON_THROW_ON_ERROR), $this->subjectSecret);
        $identity = $fact->event_type === 'order.completed' ? $purchase
            : hash_hmac('sha256', json_encode([$fact->scope_key, $fact->source, 'refund', $refund], JSON_THROW_ON_ERROR), $this->subjectSecret);

        return ['identity' => $identity, 'purchase_key' => $purchase, 'amount' => $amount, 'currency' => $currency];
    }

    private function experimentReference(TenantContext $actor, stdClass $fact): ?array
    {
        $payload = json_decode($fact->payload, true, 32, JSON_THROW_ON_ERROR);
        $reference = $payload['experiment_exposure_id'] ?? null;
        if (! is_string($reference) || ! Str::isUuid($reference) || $this->revenueExperiments === null) {
            return null;
        }
        $scope = new TenantContext($actor->organizationId, $actor->workspaceId, $fact->brand_id, $actor->actorId);

        return $this->revenueExperiments->reference($scope, $fact->contact_id, $reference, new DateTimeImmutable($fact->occurred_at));
    }

    private function persistSnapshot(TenantContext $actor, array $report): array
    {
        $fingerprint = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        $this->database->table('analytics_snapshots')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId,
            'scope_key' => $this->scopeKey($actor), 'fingerprint' => $fingerprint,
            'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => $this->clock->now()]);
        $this->record($actor, 'analytics.snapshot_created', ['snapshot_id' => $id, 'fingerprint' => $fingerprint]);

        return ['id' => $id, 'fingerprint' => $fingerprint, ...$report];
    }

    private function dimension(TenantContext $actor, stdClass $fact, string $name): ?string
    {
        $payload = json_decode($fact->payload, true, 32, JSON_THROW_ON_ERROR);
        $value = $payload[$name] ?? null;
        if (! is_string($value)) {
            return null;
        }
        if ($name === 'channel') {
            return in_array($value, ['email', 'sms', 'push', 'in_app', 'web'], true) ? $value : null;
        }
        $table = match ($name) {
            'content_id' => 'content_documents', 'campaign_id' => 'campaigns', default => null
        };

        return $table !== null && Str::isUuid($value)
            && $this->database->table($table)->where('id', $value)->where('workspace_id', $actor->workspaceId)->exists()
            ? $value : null;
    }

    public function readSnapshot(TenantContext $actor, string $id): array
    {
        $this->permit($actor);
        $row = $this->database->table('analytics_snapshots')->where('id', $id)
            ->where('workspace_id', $actor->workspaceId)->where('scope_key', $this->scopeKey($actor))->first();
        if (! $row instanceof stdClass) {
            throw new AuthorizationException('Analytics snapshot scope denied.');
        }
        $report = json_decode($row->report, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($report) || ! hash_equals($row->fingerprint, hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Analytics snapshot integrity failed.');
        }
        $lineage = $report['lineage'] ?? [];
        $rows = $this->factsQuery($actor)->whereIn('analytics_facts.id', array_keys($lineage))->get();
        if ($rows->count() !== count($lineage)) {
            throw new RuntimeException('Analytics snapshot lineage invalidated.');
        }
        foreach ($rows as $fact) {
            if ($lineage[$fact->id] !== $fact->envelope_hash || ! $this->readable($actor, $fact)) {
                throw new RuntimeException('Analytics snapshot purpose, identity, integrity or retention invalidated.');
            }
        }

        if (($report['definition']['kind'] ?? null) === 'performance') {
            foreach ($rows as $fact) {
                if (($report['dimension_lineage'][$fact->id] ?? null) !== $this->dimension($actor, $fact, $report['definition']['dimension'])) {
                    throw new RuntimeException('Analytics dimension lineage invalidated.');
                }
            }
        }

        if (($report['definition']['kind'] ?? null) === 'revenue') {
            foreach ($rows as $fact) {
                if ($fact->event_type === 'order.completed'
                    && ($report['experiment_lineage'][$fact->id] ?? null) !== $this->experimentReference($actor, $fact)) {
                    throw new RuntimeException('Revenue experiment reference invalidated.');
                }
            }
        }

        return ['id' => $id, 'fingerprint' => $row->fingerprint, ...$report];
    }

    /** Privacy erasure/identity change invalidates all workspace-derived snapshots, without rewriting Events. */
    public function invalidateSubject(TenantContext $actor, string $contactId): void
    {
        $this->permit($actor);
        if (! $this->access->allows($actor, PermissionCatalog::CONTACT_WRITE) || ! $this->database->table('contacts')->where('id', $contactId)->where('workspace_id', $actor->workspaceId)
            ->when($actor->brandId !== null, fn (Builder $query) => $query->where('brand_id', $actor->brandId))->exists()) {
            throw new AuthorizationException('Analytics subject invalidation denied.');
        }
        $this->database->transaction(function () use ($actor, $contactId): void {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $contact = $this->database->table('contacts')->where('id', $contactId)->where('workspace_id', $actor->workspaceId)->first();
            if (! $contact instanceof stdClass) {
                throw new AuthorizationException('Analytics subject disappeared.');
            }
            $scope = new TenantContext($actor->organizationId, $actor->workspaceId, $contact->brand_id, $actor->actorId);
            $key = $this->subjectKey($scope, $contactId);
            $this->database->table('analytics_invalidations')->insertOrIgnore(['id' => hash('sha256', $this->scopeKey($scope).$key),
                'workspace_id' => $actor->workspaceId, 'scope_key' => $this->scopeKey($scope), 'subject_key' => $key,
                'created_at' => $this->clock->now()]);
            $this->database->table('analytics_facts')->where('workspace_id', $actor->workspaceId)->where('subject_key', $key)->delete();
            // Aggregates may include this subject; conservative erasure avoids retaining a stale small-cohort count.
            $this->database->table('analytics_snapshots')->where('workspace_id', $actor->workspaceId)->delete();
            $this->record($scope, 'analytics.subject_invalidated', ['subject_key' => $key]);
        }, 3);
    }

    private function permit(TenantContext $actor): int
    {
        $days = $this->privacy->retentionDays($actor);
        if (! $this->access->allows($actor, PermissionCatalog::ANALYTICS_READ)
            || ! $this->database->table('workspaces')->where('id', $actor->workspaceId)->where('organization_id', $actor->organizationId)->exists()
            || ($actor->brandId !== null && ! $this->database->table('brands')->where('id', $actor->brandId)->where('workspace_id', $actor->workspaceId)->exists())
            || $days === null || $days < 1 || $days > 365) {
            throw new AuthorizationException('Analytics scope, permission or approved retention denied.');
        }

        return $days;
    }

    private function factsQuery(TenantContext $actor): Builder
    {
        $query = $this->database->table('analytics_facts')
            ->join('customer_events', function ($join): void {
                $join->on('customer_events.id', '=', 'analytics_facts.event_id')
                    ->on('customer_events.workspace_id', '=', 'analytics_facts.workspace_id');
            })->join('event_types', 'event_types.id', '=', 'customer_events.event_type_id')
            ->whereColumn('event_types.workspace_id', 'analytics_facts.workspace_id')
            ->where('analytics_facts.workspace_id', $actor->workspaceId)
            ->select('analytics_facts.*', 'customer_events.contact_id', 'customer_events.payload',
                'customer_events.subjects', 'customer_events.source_metadata', 'customer_events.source_event_id',
                'customer_events.brand_id as canonical_brand', 'event_types.canonical_name',
                'customer_events.schema_version as canonical_schema', 'customer_events.source as canonical_source',
                'customer_events.occurred_at as canonical_occurred', 'customer_events.received_at as canonical_received');
        if ($actor->brandId !== null) {
            $query->where('analytics_facts.brand_id', $actor->brandId);
        }

        return $query;
    }

    private function readable(TenantContext $actor, stdClass $fact): bool
    {
        $scope = new TenantContext($actor->organizationId, $actor->workspaceId, $fact->brand_id, $actor->actorId);
        $occurred = new DateTimeImmutable($fact->occurred_at);
        $days = $this->privacy->retentionDays($scope);

        return $this->canonicalIntegrity($fact) && $fact->schema_version === 1 && $fact->canonical_brand === $fact->brand_id && $fact->canonical_name === $fact->event_type
            && $fact->scope_key === $this->scopeKey($scope) && hash_equals($fact->subject_key, $this->subjectKey($scope, $fact->contact_id))
            && new DateTimeImmutable($fact->expires_at) > $this->clock->now()
            && $days !== null && $occurred->modify('+'.$days.' days') > $this->clock->now()
            && ! $this->invalidated($scope, $fact->subject_key) && $this->contactMatches($scope, $fact->contact_id)
            && $this->privacy->permits($scope, $fact->contact_id, $occurred)
            && ! $this->database->table('analytics_conflicts')->where('workspace_id', $actor->workspaceId)->where('source_key', $fact->source_key)->exists();
    }

    private function canonicalIntegrity(stdClass $fact): bool
    {
        $canonical = (object) [
            'id' => $fact->event_id, 'workspace_id' => $fact->workspace_id, 'brand_id' => $fact->canonical_brand,
            'contact_id' => $fact->contact_id, 'canonical_name' => $fact->canonical_name,
            'source' => $fact->canonical_source, 'source_event_id' => $fact->source_event_id,
            'schema_version' => $fact->canonical_schema, 'occurred_at' => $fact->canonical_occurred,
            'received_at' => $fact->canonical_received, 'subjects' => $fact->subjects,
            'payload' => $fact->payload, 'source_metadata' => $fact->source_metadata,
        ];

        return $fact->canonical_schema === 1 && $fact->canonical_source === $fact->source
            && new DateTimeImmutable($fact->canonical_occurred) == new DateTimeImmutable($fact->occurred_at)
            && new DateTimeImmutable($fact->canonical_received) == new DateTimeImmutable($fact->received_at)
            && hash_equals($fact->source_key, hash('sha256', json_encode([$fact->workspace_id, $fact->scope_key,
                $fact->canonical_source, $fact->source_event_id ?? 'canonical:'.$fact->event_id], JSON_THROW_ON_ERROR)))
            && hash_equals($fact->envelope_hash, $this->envelopeHash($canonical));
    }

    private function contactMatches(TenantContext $scope, string $contactId): bool
    {
        return $this->database->table('contacts')->where('id', $contactId)->where('workspace_id', $scope->workspaceId)
            ->where('brand_id', $scope->brandId)->exists();
    }

    private function invalidated(TenantContext $scope, string $key): bool
    {
        return $this->database->table('analytics_invalidations')->where('workspace_id', $scope->workspaceId)
            ->where('scope_key', $this->scopeKey($scope))->where('subject_key', $key)->exists();
    }

    private function scopeKey(TenantContext $actor): string
    {
        return hash('sha256', json_encode([$actor->workspaceId, $actor->brandId], JSON_THROW_ON_ERROR));
    }

    private function subjectKey(TenantContext $actor, string $contactId): string
    {
        return hash_hmac('sha256', $this->scopeKey($actor).':'.$contactId, $this->subjectSecret);
    }

    private function envelopeHash(stdClass $event): string
    {
        return hash('sha256', json_encode([$event->id, $event->workspace_id, $event->brand_id, $event->contact_id,
            $event->canonical_name, $event->source, $event->source_event_id, $event->schema_version,
            (new DateTimeImmutable($event->occurred_at))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            (new DateTimeImmutable($event->received_at))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            json_decode($event->subjects, true, 32, JSON_THROW_ON_ERROR),
            json_decode($event->payload, true, 32, JSON_THROW_ON_ERROR),
            json_decode($event->source_metadata, true, 32, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR));
    }

    private function record(TenantContext $actor, string $action, array $evidence): void
    {
        $this->audit->record($actor->workspaceId, $action, $evidence, $actor->brandId, $actor->actorId);
    }
}
