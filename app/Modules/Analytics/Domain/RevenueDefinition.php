<?php

namespace App\Modules\Analytics\Domain;

use InvalidArgumentException;

final readonly class RevenueDefinition
{
    public const CURRENCIES = ['USD' => 2, 'EUR' => 2, 'GBP' => 2, 'PKR' => 2, 'JPY' => 0];

    public const TOUCHES = ['message.clicked', 'product.viewed'];

    public function __construct(public string $model = 'last_touch', public int $lookbackSeconds = 86400,
        public int $ltvHorizonSeconds = 86400, public string $cohortEvent = 'contact.created')
    {
        if (! in_array($model, ['first_touch', 'last_touch'], true) || $lookbackSeconds < 1
            || $lookbackSeconds > 7 * 86400 || $ltvHorizonSeconds < 1 || $ltvHorizonSeconds > 7 * 86400
            || ! in_array($cohortEvent, MetricDefinition::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported revenue definition.');
        }
    }

    public function toArray(): array
    {
        return ['kind' => 'revenue', 'version' => 1, 'model' => $this->model, 'lookback_seconds' => $this->lookbackSeconds,
            'ltv_horizon_seconds' => $this->ltvHorizonSeconds, 'cohort_event' => $this->cohortEvent,
            'currencies' => self::CURRENCIES, 'touch_events' => self::TOUCHES, 'timezone' => 'UTC',
            'money_unit' => 'integer_minor_units', 'causal' => false, 'fx' => 'none',
            'equal_time_touch_policy' => 'excluded_not_proven_prior', 'refund_policy' => 'purchase_cohort_as_of_cutoff',
            'history' => 'all_currently_retained_admitted_facts_bounded_1000'];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
