<?php

namespace App\Modules\Analytics\Domain;

use InvalidArgumentException;

final readonly class BehaviorDefinition
{
    public function __construct(
        public string $kind,
        public array $events,
        public int $conversionSeconds = 86400,
        public int $binSeconds = 86400,
        public int $bins = 7,
        public string $dimension = 'channel',
    ) {
        $size = count($events);
        if (! in_array($kind, ['funnel', 'retention', 'lifecycle', 'performance'], true)
            || ! array_is_list($events) || $size < 1 || $size > 8
            || ($kind === 'funnel' && $size < 2) || ($kind === 'retention' && $size !== 2)
            || ($kind === 'lifecycle' && $size !== 1)
            || $conversionSeconds < 1 || $conversionSeconds > 7 * 86400
            || $binSeconds < 3600 || $binSeconds > 7 * 86400 || $bins < 1 || $bins > 31
            || $bins * $binSeconds > 31 * 86400
            || ! in_array($dimension, ['channel', 'content_id', 'campaign_id'], true)) {
            throw new InvalidArgumentException('Unsupported bounded behavior definition.');
        }
        foreach ($events as $event) {
            if (! is_string($event) || ! in_array($event, MetricDefinition::EVENTS, true)) {
                throw new InvalidArgumentException('Unregistered behavior event.');
            }
        }
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'events' => $this->events, 'version' => 1,
            'conversion_seconds' => $this->conversionSeconds, 'bin_seconds' => $this->binSeconds,
            'bins' => $this->bins, 'dimension' => $this->dimension, 'unit' => 'scoped_subject',
            'timezone' => 'UTC', 'order' => 'occurrence_then_canonical_event_id',
            'start_policy' => 'first_observed_in_selected_period', 'causal' => false];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
