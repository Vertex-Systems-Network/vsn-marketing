<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

final readonly class ConnectorCapabilityCandidate
{
    /**
     * @param  list<string>  $authSchemes
     * @param  array<string, list<string>>  $scopes
     * @param  array<string, mixed>  $declaredLimits
     * @param  list<string>  $unknownSemantics
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $operationId,
        public array $authSchemes,
        public array $scopes,
        public array $declaredLimits,
        public array $unknownSemantics,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'operation_id' => $this->operationId,
            'auth_schemes' => $this->authSchemes,
            'scopes' => $this->scopes,
            'declared_limits' => $this->declaredLimits,
            'unknown_semantics' => $this->unknownSemantics,
        ];
    }
}
