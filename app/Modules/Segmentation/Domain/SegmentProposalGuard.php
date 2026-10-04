<?php

namespace App\Modules\Segmentation\Domain;

final class SegmentProposalGuard
{
    public function containsSensitiveLiteral(string $value): bool
    {
        return preg_match('/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i', $value) === 1
            || preg_match('/\\b(?:sk|rk|ghp|github_pat)_[A-Za-z0-9_-]{10,}\\b/i', $value) === 1;
    }

    public function requestsUnsafeAuthority(string $intent): bool
    {
        return preg_match('/\b(?:ignore|bypass|override)\b.{0,40}\b(?:policy|instructions|rules|authorization|permissions)\b/i', $intent) === 1
            || preg_match('/\b(?:use|emit|execute|write|run)\s+(?:raw\s+)?sql\b/i', $intent) === 1
            || preg_match('/\b(?:show|query|include|access|read)\b.{0,40}\b(?:all|other)\s+(?:tenants?|workspaces?)\b/i', $intent) === 1
            || preg_match('/\b(?:admin|secret|hidden)\s+(?:columns?|fields?)\b/i', $intent) === 1;
    }

    public function requiresClarification(string $intent): bool
    {
        return preg_match('/\b(?:high[ -]value|best|engaged|active|recent|recently)\s+(?:customers?|users?|leads?|contacts?)\b/i', $intent) === 1
            || preg_match('/\brecently\s+active\b/i', $intent) === 1;
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
