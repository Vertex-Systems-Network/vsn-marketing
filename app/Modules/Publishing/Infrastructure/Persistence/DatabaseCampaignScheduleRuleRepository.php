<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseCampaignScheduleRuleRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(CampaignScheduleRuleSet $ruleSet): CampaignScheduleRuleSet
    {
        return $this->database->connection()->transaction(function () use ($ruleSet): CampaignScheduleRuleSet {
            $existing = $this->database->connection()->table('campaign_schedule_rule_sets')
                ->where('workspace_id', $ruleSet->workspaceId)
                ->where('idempotency_key', $ruleSet->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrate($existing);
                $this->assertReplay($stored, $ruleSet);

                return $stored;
            }

            $latest = $this->database->connection()->table('campaign_schedule_rule_sets')
                ->where('workspace_id', $ruleSet->workspaceId)
                ->where('channel', $ruleSet->channel)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                if ($ruleSet->versionNumber !== 1 || $ruleSet->parentRuleSetId !== null) {
                    throw new InvalidArgumentException('First campaign schedule rule version must be version 1 with no parent.');
                }
            } else {
                $latestRule = $this->hydrate($latest);
                if (
                    $ruleSet->versionNumber !== $latestRule->versionNumber + 1
                    || $ruleSet->parentRuleSetId !== $latestRule->id
                ) {
                    throw new InvalidArgumentException('Campaign schedule rule version must advance exactly from the latest canonical rule set.');
                }
            }

            $this->denyIfForeignReferenceExists(
                'campaign_schedule_rule_sets',
                $ruleSet->workspaceId,
                $ruleSet->id,
            );

            $inserted = $this->database->connection()->table('campaign_schedule_rule_sets')->insertOrIgnore([
                'id' => $ruleSet->id,
                'workspace_id' => $ruleSet->workspaceId,
                'parent_rule_set_id' => $ruleSet->parentRuleSetId,
                'channel' => $ruleSet->channel,
                'version_number' => $ruleSet->versionNumber,
                'timezone_id' => $ruleSet->timezoneId,
                'slots' => json_encode($ruleSet->slots, JSON_THROW_ON_ERROR),
                'rule_hash' => $ruleSet->ruleHash,
                'idempotency_key' => $ruleSet->idempotencyKey,
                'created_by_actor_id' => $ruleSet->createdByActorId,
                'created_at' => $ruleSet->createdAt,
            ]);

            if ($inserted !== 1) {
                $winner = $this->database->connection()->table('campaign_schedule_rule_sets')
                    ->where('workspace_id', $ruleSet->workspaceId)
                    ->where(function ($query) use ($ruleSet): void {
                        $query->where('id', $ruleSet->id)
                            ->orWhere('idempotency_key', $ruleSet->idempotencyKey)
                            ->orWhere(function ($nested) use ($ruleSet): void {
                                $nested->where('channel', $ruleSet->channel)
                                    ->where('version_number', $ruleSet->versionNumber);
                            });
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof stdClass) {
                    throw new InvalidArgumentException('Campaign schedule rule identity/version conflicts with existing state.');
                }

                $stored = $this->hydrate($winner);
                $this->assertReplay($stored, $ruleSet);

                return $stored;
            }

            return $ruleSet;
        });
    }

    public function findByIdempotency(string $workspaceId, string $idempotencyKey): ?CampaignScheduleRuleSet
    {
        $row = $this->database->connection()->table('campaign_schedule_rule_sets')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function find(string $workspaceId, string $ruleSetId): ?CampaignScheduleRuleSet
    {
        $row = $this->database->connection()->table('campaign_schedule_rule_sets')
            ->where('workspace_id', $workspaceId)
            ->where('id', $ruleSetId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceExists('campaign_schedule_rule_sets', $workspaceId, $ruleSetId);

        return null;
    }

    public function latest(string $workspaceId, string $channel): ?CampaignScheduleRuleSet
    {
        $row = $this->database->connection()->table('campaign_schedule_rule_sets')
            ->where('workspace_id', $workspaceId)
            ->where('channel', $channel)
            ->orderByDesc('version_number')
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function denyIfForeignReferenceExists(string $table, string $workspaceId, string $id): void
    {
        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign schedule rule reference access denied.');
        }
    }

    private function hydrate(stdClass $row): CampaignScheduleRuleSet
    {
        try {
            $slots = json_decode((string) $row->slots, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Campaign schedule rule slots are not valid JSON.', previous: $exception);
        }

        if (! is_array($slots)) {
            throw new InvalidArgumentException('Campaign schedule rule slots must decode to a list.');
        }

        return new CampaignScheduleRuleSet(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            parentRuleSetId: $row->parent_rule_set_id === null ? null : (string) $row->parent_rule_set_id,
            channel: (string) $row->channel,
            versionNumber: (int) $row->version_number,
            timezoneId: (string) $row->timezone_id,
            slots: $slots,
            ruleHash: (string) $row->rule_hash,
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function assertReplay(
        CampaignScheduleRuleSet $stored,
        CampaignScheduleRuleSet $requested,
    ): void {
        if (
            $stored->id !== $requested->id
            || $stored->workspaceId !== $requested->workspaceId
            || $stored->parentRuleSetId !== $requested->parentRuleSetId
            || $stored->channel !== $requested->channel
            || $stored->versionNumber !== $requested->versionNumber
            || $stored->timezoneId !== $requested->timezoneId
            || $stored->slots !== $requested->slots
            || ! hash_equals($stored->ruleHash, $requested->ruleHash)
            || $stored->createdByActorId !== $requested->createdByActorId
            || $stored->createdAt->format('U.u') !== $requested->createdAt->format('U.u')
        ) {
            throw new InvalidArgumentException('Campaign schedule rule replay conflicts with existing immutable rule state.');
        }
    }
}
