<?php

namespace App\Modules\Experiments\Domain;

use InvalidArgumentException;

final readonly class OptimizationProposal
{
    /** @param list<string> $sourceIds */
    public function __construct(
        public string $traceId,
        public string $promptHash,
        public string $contextHash,
        public array $sourceIds,
        public CampaignExperimentMatrix $candidate,
        public string $uncertainty,
        public string $risk,
        public int $costCeilingMinor,
        public int $actualCostMinor,
    ) {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $traceId)
            || ! preg_match('/^[0-9a-f]{64}$/D', $promptHash)
            || ! preg_match('/^[0-9a-f]{64}$/D', $contextHash)
            || ! array_is_list($sourceIds) || $sourceIds === [] || count($sourceIds) > 8
            || count($sourceIds) !== count(array_unique($sourceIds))
            || ! in_array($uncertainty, ['low', 'medium', 'high'], true)
            || ! in_array($risk, ['low', 'medium', 'high'], true)
            || $costCeilingMinor < 1 || $actualCostMinor < 0 || $actualCostMinor > $costCeilingMinor) {
            throw new InvalidArgumentException('Optimization proposal evidence, risk or budget invalid.');
        }
        foreach ($sourceIds as $id) {
            if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $id)) {
                throw new InvalidArgumentException('Optimization source reference invalid.');
            }
        }
    }

    public function canonical(): array
    {
        $sources = $this->sourceIds;
        sort($sources, SORT_STRING);
        $arms = $this->candidate->variants;
        ksort($arms, SORT_STRING);

        return ['trace_id' => $this->traceId, 'prompt_hash' => $this->promptHash,
            'context_hash' => $this->contextHash, 'source_ids' => $sources, 'candidate' => $arms,
            'uncertainty' => $this->uncertainty, 'risk' => $this->risk,
            'cost_ceiling_minor' => $this->costCeilingMinor, 'actual_cost_minor' => $this->actualCostMinor];
    }

    public function fingerprint(string $workspaceId, string $bindingId, string $matrixHash): string
    {
        return hash('sha256', json_encode([$workspaceId, $bindingId, $matrixHash, $this->canonical()], JSON_THROW_ON_ERROR));
    }
}
