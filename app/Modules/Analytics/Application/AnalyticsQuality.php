<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Analytics\Domain\AnalyticsSourceVerifier;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class AnalyticsQuality
{
    public function __construct(private AnalyticsFacts $facts, private AnalyticsPrivacy $privacy,
        private DatabaseManager $database, private Clock $clock, private AuditRecorder $audit, private ?AnalyticsSourceVerifier $verifier = null) {}

    /** Immutable checkpoint inputs contain scoped source keys/hashes, never raw event or contact payloads. */
    public function reconcile(TenantContext $actor, string $requestId, string $source, string $eventType,
        DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $cutoff, ?array $checkpoint = null): array
    {
        $this->facts->authorize($actor);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $requestId) || ! preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $source)
            || ! in_array($eventType, MetricDefinition::EVENTS, true)
            || $start->getOffset() !== 0 || $end->getOffset() !== 0 || $cutoff->getOffset() !== 0
            || $start >= $end || $end > $cutoff || $cutoff > $this->clock->now()
            || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400) {
            throw new InvalidArgumentException('Bounded UTC source reconciliation required.');
        }
        $scope = $this->scope($actor);
        $input = ['scope' => $scope, 'source' => $source, 'event_type' => $eventType,
            'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM), 'cutoff' => $cutoff->format(DATE_ATOM),
            'checkpoint' => $checkpoint];
        if ($checkpoint !== null) {
            $this->validateCheckpoint($checkpoint, $input);
            if ($this->verifier === null || ! $this->verifier->verifies($actor, $checkpoint)) {
                throw new AuthorizationException('Independent source checkpoint verification required.');
            }
        }
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $id = hash('sha256', $scope.':'.$requestId);

        return $this->database->transaction(function () use ($actor, $source, $eventType, $start, $end, $cutoff, $checkpoint, $scope, $id, $inputHash): array {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $existing = $this->database->table('analytics_reconciliations')->where('id', $id)->first();
            if ($existing instanceof stdClass) {
                if (! hash_equals($existing->input_hash, $inputHash)) {
                    throw new RuntimeException('Reconciliation replay evidence changed.');
                }

                return $this->read($actor, $id);
            }
            $events = $this->database->table('customer_events')->join('event_types', 'event_types.id', '=', 'customer_events.event_type_id')
                ->where('customer_events.workspace_id', $actor->workspaceId)->where('event_types.workspace_id', $actor->workspaceId)
                ->where('customer_events.brand_id', $actor->brandId)->where('event_types.canonical_name', $eventType)
                ->where('customer_events.source', $source)->where('customer_events.occurred_at', '>=', $start)
                ->where('customer_events.occurred_at', '<', $end)->where('customer_events.received_at', '<=', $cutoff)
                ->orderBy('customer_events.id')->limit(AnalyticsFacts::MAX_FACTS + 1)
                ->get(['customer_events.id', 'customer_events.contact_id', 'customer_events.source_event_id', 'customer_events.occurred_at', 'customer_events.received_at']);
            if ($events->count() > AnalyticsFacts::MAX_FACTS) {
                throw new RuntimeException('Source observation bound exceeded; no truncated quality result.');
            }
            $observed = $lineage = $receipts = [];
            $missingProjection = $duplicates = $late = $excluded = 0;
            $maxLag = 0;
            $days = $this->privacy->retentionDays($actor);
            foreach ($events as $event) {
                $occurred = new DateTimeImmutable($event->occurred_at);
                $received = new DateTimeImmutable($event->received_at);
                if (! $this->facts->qualityReceiptAllowed($actor, $event) || $days === null || $occurred->modify('+'.$days.' days') <= $this->clock->now()
                    || ! $this->privacy->permits($actor, $event->contact_id, $occurred)
                    || ! $this->database->table('contacts')->where('id', $event->contact_id)->where('workspace_id', $actor->workspaceId)
                        ->where('brand_id', $actor->brandId)->exists()) {
                    $excluded++;

                    continue;
                }
                if (! is_string($event->source_event_id) || $event->source_event_id === '') {
                    $excluded++;

                    continue;
                }
                $key = hash('sha256', json_encode([$actor->workspaceId, $scope, $source, $event->source_event_id], JSON_THROW_ON_ERROR));
                $duplicates += isset($observed[$key]) ? 1 : 0;
                $observed[$key] = true;
                $receipts[$event->id] = hash('sha256', json_encode([$event->contact_id, $event->source_event_id, $event->occurred_at, $event->received_at], JSON_THROW_ON_ERROR));
                $lag = max(0, $received->getTimestamp() - $occurred->getTimestamp());
                $maxLag = max($maxLag, $lag);
                $late += $received >= $end ? 1 : 0;
                $fact = $this->database->table('analytics_facts')->where('workspace_id', $actor->workspaceId)
                    ->where('scope_key', $scope)->where('event_id', $event->id)->where('created_at', '<=', $cutoff)->first();
                if ($fact instanceof stdClass) {
                    $lineage[$fact->id] = $fact->envelope_hash;
                } else {
                    $missingProjection++;
                }
            }
            ksort($observed);
            ksort($lineage);
            $conflicts = $this->database->table('analytics_conflicts')->where('workspace_id', $actor->workspaceId)
                ->where('scope_key', $scope)->whereIn('source_key', array_keys($observed))->where('created_at', '<=', $cutoff)->count();
            $expected = $checkpoint['keys'] ?? null;
            $missing = $expected === null ? null : count(array_diff(array_keys($expected), array_keys($observed)));
            $unexpected = $expected === null ? null : count(array_diff(array_keys($observed), array_keys($expected)));
            $drift = 0;
            if ($expected !== null) {
                foreach ($lineage as $factId => $hash) {
                    $key = $this->database->table('analytics_facts')->where('id', $factId)->value('source_key');
                    $drift += isset($expected[$key]) && ! hash_equals($expected[$key], $hash) ? 1 : 0;
                }
            }
            $report = ['schema_version' => 1, 'scope_key' => $scope, 'source_hash' => hash('sha256', $source),
                'event_type' => $eventType, 'metric_version' => 1, 'start_utc' => $start->format(DATE_ATOM),
                'end_utc' => $end->format(DATE_ATOM), 'receipt_cutoff_utc' => $cutoff->format(DATE_ATOM),
                'received_events' => $events->count(), 'observed_keys' => count($observed), 'excluded' => $excluded,
                'missing_projection' => $missingProjection, 'duplicates' => $duplicates, 'late' => $late,
                'conflicts' => $conflicts, 'max_receipt_lag_seconds' => $maxLag, 'expected_total' => $expected === null ? null : count($expected),
                'missing_source_keys' => $missing, 'unexpected_source_keys' => $unexpected, 'drifted_hashes' => $drift,
                'coverage' => $expected === null ? 'unknown' : (($missing === 0 && $unexpected === 0 && $missingProjection === 0
                    && $conflicts === 0 && $drift === 0 && $excluded === 0 && $duplicates === 0) ? 'checkpoint_matched' : 'discrepant'),
                'source_truth_certified' => false, 'publication_authorized' => false, 'lineage' => $lineage, 'receipt_lineage' => $receipts,
                'affected_metric_versions' => $this->affectedMetrics($actor, $eventType, $lineage)];
            $fingerprint = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
            $this->database->table('analytics_reconciliations')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId,
                'scope_key' => $scope, 'input_hash' => $inputHash, 'fingerprint' => $fingerprint,
                'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => $this->clock->now()]);

            $this->audit->record($actor->workspaceId, 'analytics.source_reconciled', ['reconciliation_id' => $id, 'fingerprint' => $fingerprint], $actor->brandId, $actor->actorId);

            return ['id' => $id, 'fingerprint' => $fingerprint, ...$report];
        }, 3);
    }

    public function read(TenantContext $actor, string $id): array
    {
        $this->facts->authorize($actor);
        $row = $this->database->table('analytics_reconciliations')->where('id', $id)->where('workspace_id', $actor->workspaceId)
            ->where('scope_key', $this->scope($actor))->first();
        if (! $row instanceof stdClass) {
            throw new AuthorizationException('Reconciliation scope denied.');
        }
        $report = json_decode($row->report, true, 32, JSON_THROW_ON_ERROR);
        if (! hash_equals($row->fingerprint, hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Reconciliation evidence corrupted.');
        }
        // Revalidate every referenced fact with the same current privacy/integrity policy as reports.
        $this->facts->validateLineage($actor, $report['lineage']);
        foreach ($report['receipt_lineage'] as $eventId => $hash) {
            $event = $this->database->table('customer_events')->where('id', $eventId)->where('workspace_id', $actor->workspaceId)
                ->where('brand_id', $actor->brandId)->first();
            if (! $event instanceof stdClass || ! $this->facts->qualityReceiptAllowed($actor, $event) || ! hash_equals($hash, hash('sha256', json_encode([$event->contact_id, $event->source_event_id, $event->occurred_at, $event->received_at], JSON_THROW_ON_ERROR)))
                || ! $this->privacy->permits($actor, $event->contact_id, new DateTimeImmutable($event->occurred_at))
                || ! $this->database->table('contacts')->where('id', $event->contact_id)->where('workspace_id', $actor->workspaceId)->where('brand_id', $actor->brandId)->exists()) {
                throw new RuntimeException('Quality receipt privacy or identity invalidated.');
            }
        }
        if (new DateTimeImmutable($report['start_utc']) < $this->clock->now()->modify('-'.$this->privacy->retentionDays($actor).' days')) {
            throw new RuntimeException('Reconciliation evidence outside current retention.');
        }

        return ['id' => $id, 'fingerprint' => $row->fingerprint, ...$report];
    }

    public function recent(TenantContext $actor): array
    {
        $this->facts->authorize($actor);
        $ids = $this->database->table('analytics_reconciliations')->where('workspace_id', $actor->workspaceId)
            ->where('scope_key', $this->scope($actor))->orderByDesc('created_at')->limit(20)->pluck('id');
        $reports = [];
        foreach ($ids as $id) {
            try {
                $r = $this->read($actor, $id);
                unset($r['lineage'], $r['receipt_lineage']);
                $reports[] = $r;
            } catch (RuntimeException) {
                // Invalid current privacy/integrity evidence is never displayed.
            }
        }

        return $reports;
    }

    private function affectedMetrics(TenantContext $actor, string $eventType, array $lineage): array
    {
        $rows = $this->database->table('analytics_snapshots')->where('workspace_id', $actor->workspaceId)
            ->where('scope_key', $this->scope($actor))->orderByDesc('created_at')->limit(20)->get();
        $affected = [];
        foreach ($rows as $row) {
            $r = json_decode($row->report, true, 32, JSON_THROW_ON_ERROR);
            if (($r['definition']['event_type'] ?? null) === $eventType || array_intersect_key($r['lineage'], $lineage) !== []) {
                $affected[] = ['snapshot_id' => $row->id, 'definition_hash' => $r['definition_hash'],
                    'version' => $r['definition']['version']];
            }
        }

        return $affected;
    }

    private function validateCheckpoint(array $c, array $input): void
    {
        if (array_diff(array_keys($c), ['scope', 'source', 'event_type', 'start', 'end', 'cutoff', 'keys', 'reference']) !== []
            || ! is_array($c['keys'] ?? null) || count($c['keys']) > AnalyticsFacts::MAX_FACTS
            || ! is_string($c['reference'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $c['reference'])) {
            throw new InvalidArgumentException('Invalid independently verified checkpoint.');
        }
        foreach (['scope', 'source', 'event_type', 'start', 'end', 'cutoff'] as $field) {
            if (($c[$field] ?? null) !== $input[$field]) {
                throw new InvalidArgumentException('Checkpoint scope, period or source mismatch.');
            }
        }
        foreach ($c['keys'] as $key => $hash) {
            if (! is_string($key) || ! preg_match('/^[a-f0-9]{64}$/', $key) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new InvalidArgumentException('Only source-key and envelope-hash references allowed.');
            }
        }
    }

    private function scope(TenantContext $actor): string
    {
        return hash('sha256', json_encode([$actor->workspaceId, $actor->brandId], JSON_THROW_ON_ERROR));
    }
}
