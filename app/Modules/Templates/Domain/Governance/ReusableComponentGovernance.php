<?php

namespace App\Modules\Templates\Domain\Governance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReusableComponentGovernance
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<ReusableGovernanceEvent>  $events
     */
    public function __construct(
        public string $workspaceId,
        public string $componentId,
        public string $componentVersionId,
        public ReusableComponentScope $scope,
        public array $events,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'componentId' => $this->componentId,
            'componentVersionId' => $this->componentVersionId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Reusable governance {$field} must not be empty.");
            }
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported reusable governance schema version: {$this->schemaVersion}");
        }

        if ($this->events === []) {
            throw new InvalidArgumentException('Reusable governance requires at least one audit event.');
        }

        $previous = null;

        foreach ($this->events as $index => $event) {
            if ($event instanceof ReusableGovernanceEvent === false) {
                throw new InvalidArgumentException('Reusable governance events must contain ReusableGovernanceEvent values.');
            }

            if ($event->sequence !== $index + 1) {
                throw new InvalidArgumentException('Reusable governance event sequence must be contiguous.');
            }

            if ($index === 0) {
                if ($event->fromStatus !== null || $event->toStatus !== ReusableApprovalStatus::Draft) {
                    throw new InvalidArgumentException('Reusable governance must initialize in draft status.');
                }

                $previous = $event;

                continue;
            }

            if ($previous === null || $event->fromStatus !== $previous->toStatus) {
                throw new InvalidArgumentException('Reusable governance event status chain is invalid.');
            }

            if ($previous->toStatus->canTransitionTo($event->toStatus) === false) {
                throw new InvalidArgumentException(
                    "Invalid reusable governance transition from {$previous->toStatus->value} to {$event->toStatus->value}.",
                );
            }

            if ($event->occurredAt < $previous->occurredAt) {
                throw new InvalidArgumentException('Reusable governance transition time must not move backwards.');
            }

            $previous = $event;
        }
    }

    /**
     * @param  array<string, mixed>  $auditProvenance
     */
    public static function initial(
        string $workspaceId,
        string $componentId,
        string $componentVersionId,
        ReusableComponentScope $scope,
        string $actorId,
        array $auditProvenance,
        DateTimeImmutable $at,
    ): self {
        return new self(
            workspaceId: $workspaceId,
            componentId: $componentId,
            componentVersionId: $componentVersionId,
            scope: $scope,
            events: [
                new ReusableGovernanceEvent(
                    sequence: 1,
                    fromStatus: null,
                    toStatus: ReusableApprovalStatus::Draft,
                    actorId: $actorId,
                    auditProvenance: $auditProvenance,
                    occurredAt: $at,
                ),
            ],
        );
    }

    public function status(): ReusableApprovalStatus
    {
        return $this->events[array_key_last($this->events)]->toStatus;
    }

    /**
     * @param  array<string, mixed>  $auditProvenance
     */
    public function transitionTo(
        ReusableApprovalStatus $next,
        string $actorId,
        array $auditProvenance,
        DateTimeImmutable $at,
    ): self {
        $current = $this->status();

        if ($next === $current) {
            return $this;
        }

        if ($current->canTransitionTo($next) === false) {
            throw new InvalidArgumentException(
                "Invalid reusable governance transition from {$current->value} to {$next->value}.",
            );
        }

        $events = $this->events;
        $events[] = new ReusableGovernanceEvent(
            sequence: count($events) + 1,
            fromStatus: $current,
            toStatus: $next,
            actorId: $actorId,
            auditProvenance: $auditProvenance,
            occurredAt: $at,
        );

        return new self(
            workspaceId: $this->workspaceId,
            componentId: $this->componentId,
            componentVersionId: $this->componentVersionId,
            scope: $this->scope,
            events: $events,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'workspace_id' => $this->workspaceId,
            'component_id' => $this->componentId,
            'component_version_id' => $this->componentVersionId,
            'scope' => $this->scope->value,
            'status' => $this->status()->value,
            'events' => array_map(
                static fn (ReusableGovernanceEvent $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }
}
