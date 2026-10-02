<?php

namespace App\Modules\Experiments\Domain;

use InvalidArgumentException;

final readonly class ExperimentPlan
{
    /** @param array<string, int> $weights */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $brandId,
        public string $layer,
        public string $unitKind,
        public array $weights,
        public string $control,
        public ?string $holdout,
    ) {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
            || $workspaceId === '' || $brandId === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $layer)
            || ! in_array($unitKind, ['contact', 'company'], true) || count($weights) < 2 || count($weights) > 16
            || ! array_key_exists($control, $weights) || ($holdout !== null && ($holdout === $control || ! array_key_exists($holdout, $weights)))) {
            throw new InvalidArgumentException('Invalid experiment scope, unit or control/holdout.');
        }
        $sum = 0;
        foreach ($weights as $variant => $weight) {
            if (! is_string($variant) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $variant)
                || ! is_int($weight) || $weight < 1 || $weight > 9999) {
                throw new InvalidArgumentException('Invalid variant allocation.');
            }
            $sum += $weight;
        }
        if ($sum !== 10000) {
            throw new InvalidArgumentException('Variant allocation must sum to 10000 basis points.');
        }
    }

    public function canonical(): array
    {
        $weights = $this->weights;
        ksort($weights, SORT_STRING);

        return ['id' => $this->id, 'workspace_id' => $this->workspaceId, 'brand_id' => $this->brandId,
            'layer' => $this->layer, 'unit_kind' => $this->unitKind, 'weights' => $weights,
            'control' => $this->control, 'holdout' => $this->holdout];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR));
    }
}
