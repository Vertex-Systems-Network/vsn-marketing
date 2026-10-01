<?php

namespace App\Modules\AI\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final class AiAgentOutputPolicy
{
    public function validate(array $result, array $definition, TenantContext $scope, array $references): array
    {
        $validated = (new AiStructuredOutputValidator)->validate($result, $definition['schema_id'], $definition['schema'], $scope, $references);
        $output = $validated['output'];
        if ($output['agent_id'] !== $definition['agent_id'] || array_diff($output['tool_ids'], $definition['tools']) !== []
            || count($output['reference_ids']) !== count(array_unique($output['reference_ids']))
            || ($output['decision'] === 'proposal' && ($output['reference_ids'] === [] || $output['recommendations'] === [] || $output['reason_code'] !== 'evidence_grounded'))
            || ($output['decision'] === 'insufficient_evidence' && ($output['reference_ids'] !== [] || $output['recommendations'] !== [] || $output['tool_ids'] !== [] || $output['reason_code'] !== 'needs_evidence'))) {
            throw new InvalidArgumentException('Agent evidence/tool policy rejected.');
        }
        foreach ($output['recommendations'] as $text) {
            if (preg_match('#https?://|VSN_INTERNAL_|<script\b#i', $text)) {
                throw new InvalidArgumentException('Agent output disclosure policy rejected.');
            }
        }

        return $validated;
    }
}
