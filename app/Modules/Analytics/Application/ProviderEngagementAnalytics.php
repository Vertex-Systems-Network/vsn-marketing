<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Analytics\EngagementFact;
use App\Modules\Providers\Domain\Analytics\EngagementFactQuality;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class ProviderEngagementAnalytics
{
    public const MAX_RECENT = 50;

    public function __construct(
        private AnalyticsFacts $facts,
        private AnalyticsPrivacy $privacy,
        private DatabaseManager $database,
        private Clock $clock,
        private AuditRecorder $audit,
    ) {}

    public function admit(TenantContext $actor, EngagementFact $fact): string
    {
        $days = $this->permit($actor);
        if ($fact->tenantId !== $actor->workspaceId || ($fact->brandId !== null && $fact->brandId !== $actor->brandId)) {
            throw new AuthorizationException('Provider engagement analytics scope denied.');
        }
        EngagementFactQuality::validate([$fact]);

        $observed = new DateTimeImmutable($fact->observedAt);
        $received = new DateTimeImmutable($fact->receivedAtValue());
        $now = $this->clock->now();
        $expires = $observed->modify('+'.$days.' days');
        if ($observed->getOffset() !== 0 || $received->getOffset() !== 0 || $observed > $received || $received > $now || $expires <= $now) {
            throw new InvalidArgumentException('Provider engagement analytics time or retention boundary denied.');
        }

        $definition = $fact->definition();
        $lineageHash = hash('sha256', $fact->sourceLineage);
        $sourceKey = hash('sha256', json_encode([
            $actor->workspaceId, $actor->brandId, $fact->providerKey, $fact->metric, $lineageHash, $observed->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode([
            $sourceKey, $definition, $fact->value, $fact->isTotalKnown, $observed->format(DATE_ATOM), $received->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $fact, $definition, $lineageHash, $sourceKey, $fingerprint, $observed, $received, $expires, $now): string {
            $this->database->table('workspaces')->where('id', $actor->workspaceId)->lockForUpdate()->first();
            $existing = $this->database->table('provider_engagement_facts')->where('source_key', $sourceKey)->first();
            if ($existing instanceof stdClass) {
                if ($existing->workspace_id !== $actor->workspaceId
                    || ($actor->brandId !== null && $existing->brand_id !== $actor->brandId)) {
                    throw new AuthorizationException('Provider engagement replay scope denied.');
                }
                if (hash_equals((string) $existing->fingerprint, $fingerprint)) {
                    return 'replayed';
                }
                $this->record($actor, 'analytics.provider_engagement_conflict', [
                    'source_key' => $sourceKey, 'candidate_fingerprint' => $fingerprint,
                ]);

                return 'conflict';
            }

            $id = (string) Str::uuid();
            $this->database->table('provider_engagement_facts')->insert([
                'id' => $id,
                'workspace_id' => $actor->workspaceId,
                'brand_id' => $actor->brandId,
                'provider_key' => $fact->providerKey,
                'metric' => $fact->metric,
                'metric_definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                'value' => $fact->value,
                'source_key' => $sourceKey,
                'source_lineage_hash' => $lineageHash,
                'fingerprint' => $fingerprint,
                'observed_at' => $observed,
                'received_at' => $received,
                'expires_at' => $expires,
                'is_total_known' => $fact->isTotalKnown,
                'created_at' => $now,
            ]);
            $this->record($actor, 'analytics.provider_engagement_admitted', [
                'fact_id' => $id, 'provider_key' => $fact->providerKey, 'metric' => $fact->metric,
                'source_lineage_hash' => $lineageHash, 'fingerprint' => $fingerprint,
            ]);

            return 'admitted';
        }, 3);
    }

    public function recent(TenantContext $actor): array
    {
        $this->permit($actor);
        $query = $this->database->table('provider_engagement_facts')
            ->where('workspace_id', $actor->workspaceId)
            ->where('expires_at', '>', $this->clock->now())
            ->orderByDesc('observed_at')
            ->orderBy('id')
            ->limit(self::MAX_RECENT);
        if ($actor->brandId !== null) {
            $query->where('brand_id', $actor->brandId);
        }

        return array_map(static function (stdClass $row): array {
            $observed = new DateTimeImmutable((string) $row->observed_at);
            $received = new DateTimeImmutable((string) $row->received_at);
            $definition = json_decode((string) $row->metric_definition, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($definition)) {
                throw new RuntimeException('Provider engagement metric definition is invalid.');
            }

            return [
                'id' => (string) $row->id,
                'provider_key' => (string) $row->provider_key,
                'metric' => (string) $row->metric,
                'definition' => $definition,
                'value' => (int) $row->value,
                'observed_at' => $observed->format(DATE_ATOM),
                'received_at' => $received->format(DATE_ATOM),
                'receipt_lag_seconds' => max(0, $received->getTimestamp() - $observed->getTimestamp()),
                'delayed' => $received > $observed,
                'provider_total_status' => (bool) $row->is_total_known ? 'provider_reported_total' : 'unknown',
                'source_completeness' => 'unknown',
                'missing_provider_events' => 'unknown',
                'source_lineage_hash' => (string) $row->source_lineage_hash,
                'fingerprint' => (string) $row->fingerprint,
            ];
        }, $query->get()->all());
    }

    private function permit(TenantContext $actor): int
    {
        $this->facts->authorize($actor);
        $days = $this->privacy->retentionDays($actor);
        if ($days === null || $days < 1 || $days > 365) {
            throw new AuthorizationException('Provider engagement analytics purpose or retention denied.');
        }

        return $days;
    }

    private function record(TenantContext $actor, string $action, array $evidence): void
    {
        $this->audit->record($actor->workspaceId, $action, $evidence, $actor->brandId, $actor->actorId);
    }
}
