<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\AiCreativeDraftPolicy;
use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiCreativePolicyAuthority;
use App\Modules\AI\Domain\Contracts\AiCreativeProvider;
use App\Modules\AI\Domain\Contracts\AiGatewayOutputValidator;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class AiCreativeDraftGenerator
{
    public function __construct(
        private readonly AiCreativeCatalog $catalog,
        private readonly AiContextAssembler $contexts,
        private readonly AiCreativePolicyAuthority $authority,
        private readonly array $routes,
        private readonly array $providers,
        private readonly AiBudgetLedger $budget,
        private readonly AiCircuitBreaker $circuit,
        private readonly AiTelemetryRecorder $telemetry,
        private readonly string $region,
        private readonly int $ceiling,
    ) {}

    public function generate(TenantContext $scope, string $capability, string $version, string $brief, array $sources, string $rightsReference, string $trace, DateTimeImmutable $at): array
    {
        $definition = $this->catalog->resolve($capability, $version);
        if ($scope->brandId === null || $this->ceiling < 1 || ! (new AiContextSanitizer)->safe($brief)
            || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $rightsReference)
            || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $trace)) {
            throw new InvalidArgumentException('Creative input/scope bound rejected.');
        }
        $context = $this->contexts->assemble($scope, null, null, $sources, $at);
        if (! in_array('brand_guideline', array_column($context['manifest']['sources'], 'source_kind'), true)) {
            throw new InvalidArgumentException('Creative brand context required.');
        }
        $requestHash = hash('sha256', json_encode([$scope->toArray(), $capability, $version,
            hash('sha256', $brief), $context['manifest_sha256'], $definition['policy_sha256'], $rightsReference], JSON_THROW_ON_ERROR));
        if (! $this->authority->allowsInput($scope, $requestHash, $rightsReference)) {
            throw new InvalidArgumentException('Creative independent input rights/policy required.');
        }
        $routes = array_values(array_filter($this->routes, fn (array $route): bool => ($route['creative_policy_sha256'] ?? null) === $definition['policy_sha256']
            && ($route['evidence_kind'] ?? null) === 'offline_contract'
            && ($this->providers[$route['adapter_id'] ?? ''] ?? null) instanceof AiCreativeProvider));
        $validator = new class($definition, $context, $rightsReference) implements AiGatewayOutputValidator
        {
            public function __construct(private readonly array $definition, private readonly array $context, private readonly string $rightsReference) {}

            public function validate(array $result, array $request, TenantContext $scope): array
            {
                return (new AiCreativeDraftPolicy)->validate($result, $this->definition, $scope,
                    array_column($this->context['manifest']['sources'], 'source_id'), $this->rightsReference, $this->context['manifest_sha256']);
            }
        };
        $classification = count(array_filter($context['manifest']['sources'], static fn (array $source): bool => $source['source_kind'] !== 'brand_guideline')) > 0 ? 'approved_non_personal' : 'public';
        $gateway = new AiGateway(new AiRoutePolicy, $this->budget, $this->providers, $this->circuit, $this->telemetry, $validator);

        return $gateway->generate($routes, ['trace_id' => $trace, 'data_region' => $this->region,
            'data_classification' => $classification, 'required_capabilities' => [$capability, 'usage_telemetry'],
            'risk_tier' => 'R1', 'max_cost_minor' => $this->ceiling, 'required_tool_ids' => [],
            'output_schema_id' => $definition['output_schema_id'], 'prompt_id' => $capability,
            'prompt_version' => $version, 'context_manifest_sha256' => $context['manifest_sha256'],
            'untrusted_brief' => $brief, 'untrusted_context' => $context['untrusted_context'],
            'fallback_policy' => 'compatible_only'], $scope);
    }
}
