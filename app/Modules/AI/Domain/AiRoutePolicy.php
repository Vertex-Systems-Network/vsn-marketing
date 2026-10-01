<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/** Server-owned route filters. Client/model payloads never define the route registry. */
final class AiRoutePolicy
{
    /**
     * @param list<array<string, mixed>> $routes
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    public function eligible(array $routes, array $request): array
    {
        $workspace = $request['workspace_id'] ?? null;
        $region = $request['data_region'] ?? null;
        $classification = $request['data_classification'] ?? null;
        $capabilities = $request['required_capabilities'] ?? null;
        $risk = $request['risk_tier'] ?? null;
        $maxCost = $request['max_cost_minor'] ?? null;

        if (! is_string($workspace) || $workspace === '' || ! is_string($region) || $region === ''
            || ! is_string($classification) || $classification === '' || ! is_array($capabilities)
            || ! is_string($risk) || ! in_array($risk, ['R0', 'R1', 'R2', 'R3'], true)
            || ! is_int($maxCost) || $maxCost < 0) {
            throw new InvalidArgumentException('AI route request has missing or invalid policy fields.');
        }

        foreach ($capabilities as $capability) {
            if (! is_string($capability) || $capability === '') {
                throw new InvalidArgumentException('Required AI capabilities must be registered identifiers.');
            }
        }

        $eligible = [];
        foreach ($routes as $route) {
            if (($route['status'] ?? null) !== 'active' || ! is_string($route['id'] ?? null)
                || ! is_string($route['version'] ?? null) || ! is_string($route['adapter_id'] ?? null)
                || ! is_string($route['credential_reference'] ?? null) || $route['credential_reference'] === ''
                || ! is_array($route['workspaces'] ?? null) || ! in_array($workspace, $route['workspaces'], true)
                || ! is_array($route['data_regions'] ?? null) || ! in_array($region, $route['data_regions'], true)
                || ! is_array($route['data_classes'] ?? null) || ! in_array($classification, $route['data_classes'], true)
                || ! is_array($route['capabilities'] ?? null)
                || ! is_array($route['risk_tiers'] ?? null) || ! in_array($risk, $route['risk_tiers'], true)
                || ! is_int($route['max_reservation_minor'] ?? null)
                || $route['max_reservation_minor'] < 0 || $route['max_reservation_minor'] > $maxCost) {
                continue;
            }

            if (array_diff($capabilities, $route['capabilities']) !== []) {
                continue;
            }

            $eligible[] = $route;
        }

        usort($eligible, static fn (array $left, array $right): int => [$left['max_reservation_minor'], $left['id'], $left['version']] <=> [$right['max_reservation_minor'], $right['id'], $right['version']]);

        return $eligible;
    }
}
