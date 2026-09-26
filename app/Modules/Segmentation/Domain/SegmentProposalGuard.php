<?php

namespace App\Modules\Segmentation\Domain;

final class SegmentProposalGuard
{
    public function containsSensitiveLiteral(string $value): bool
    {
        return preg_match('/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i', $value) === 1
            || preg_match('/\\b(?:sk|rk|ghp|github_pat)_[A-Za-z0-9_-]{10,}\\b/i', $value) === 1;
    }

    /** @param array<string, mixed> $definition */
    public function assertSafeDefinition(array $definition): void
    {
        $this->visit($definition['root'], '$.root');
    }

    /** @param array<string, mixed> $node */
    private function visit(array $node, string $path): void
    {
        if ($node['type'] === 'group') {
            foreach ($node['children'] as $index => $child) {
                $this->visit($child, $path.'.children.'.$index);
            }

            return;
        }

        if ($node['type'] === 'not') {
            $this->visit($node['child'], $path.'.child');

            return;
        }

        if ($node['type'] === 'attribute' && isset($node['value']) && is_string($node['value'])
            && $this->containsSensitiveLiteral($node['value'])) {
            throw new SegmentDefinitionException('sensitive_literal_not_allowed', $path.'.value');
        }
    }
}
