<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCapabilityCandidate;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorPlanCandidate;
use App\Modules\Providers\Domain\ConnectorFactory\IngestedConnectorDescription;
use InvalidArgumentException;

final class OpenApiConnectorPlanner
{
    public const MAX_OPERATIONS = 2_000;

    /** @var list<string> */
    private const HTTP_METHODS = ['delete', 'get', 'head', 'options', 'patch', 'post', 'put', 'trace'];

    public function plan(IngestedConnectorDescription $description, string $providerKey): ConnectorPlanCandidate
    {
        if (! $description->isOpenApi()) {
            return new ConnectorPlanCandidate(
                workspaceId: $description->provenance->workspaceId,
                providerKey: $providerKey,
                provenance: $description->provenance,
                capabilities: [],
                authSchemes: [],
                servers: [],
                unknownSemantics: ['documentation_only:no_capabilities_inferred'],
            );
        }

        $document = $description->document;
        if ($document === null) {
            throw new InvalidArgumentException('OpenAPI description is missing its parsed document.');
        }

        $authSchemes = $this->authSchemes($document);
        $servers = $this->servers($document);
        if (array_key_exists('security', $document) && ! is_array($document['security'])) {
            throw new InvalidArgumentException('OpenAPI root security must be an array.');
        }

        $globalSecurity = $document['security'] ?? [];
        $capabilities = [];
        $unknown = $this->serverSemantics($servers);

        if (isset($document['webhooks'])) {
            $unknown[] = 'openapi_webhooks:not_activated';
        }

        $paths = $document['paths'] ?? [];
        if (! is_array($paths) || array_is_list($paths)) {
            throw new InvalidArgumentException('OpenAPI paths must be an object.');
        }

        if (count($paths) > 500) {
            throw new InvalidArgumentException('OpenAPI path count exceeds TASK-0083 planning bounds.');
        }

        ksort($paths, SORT_STRING);

        $operationCount = 0;

        foreach ($paths as $path => $pathItem) {
            if (! is_string($path) || ! str_starts_with($path, '/') || ! is_array($pathItem) || array_is_list($pathItem)) {
                throw new InvalidArgumentException('OpenAPI paths must be absolute path keys with object values.');
            }

            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem[$method] ?? null;
                if ($operation === null) {
                    continue;
                }

                if (! is_array($operation) || array_is_list($operation)) {
                    throw new InvalidArgumentException('OpenAPI operations must be objects.');
                }

                $operationCount++;
                if ($operationCount > self::MAX_OPERATIONS) {
                    throw new InvalidArgumentException('OpenAPI operation count exceeds TASK-0083 planning bounds.');
                }

                $security = array_key_exists('security', $operation)
                    ? (is_array($operation['security']) ? $operation['security'] : [])
                    : $globalSecurity;

                [$operationSchemes, $scopes, $securityUnknown] = $this->security($security, $authSchemes);
                $operationUnknown = $securityUnknown;

                if (isset($operation['callbacks'])) {
                    $operationUnknown[] = 'callbacks:not_activated';
                }

                if (isset($operation['links'])) {
                    $operationUnknown[] = 'links:not_activated';
                }

                $capabilities[] = new ConnectorCapabilityCandidate(
                    method: strtoupper($method),
                    path: $path,
                    operationId: is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null,
                    authSchemes: $operationSchemes,
                    scopes: $scopes,
                    declaredLimits: $this->declaredLimits($document, $pathItem, $operation),
                    unknownSemantics: array_values(array_unique($operationUnknown)),
                );
            }
        }

        return new ConnectorPlanCandidate(
            workspaceId: $description->provenance->workspaceId,
            providerKey: $providerKey,
            provenance: $description->provenance,
            capabilities: $capabilities,
            authSchemes: $authSchemes,
            servers: $servers,
            unknownSemantics: array_values(array_unique($unknown)),
        );
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, array<string, mixed>>
     */
    private function authSchemes(array $document): array
    {
        $components = $document['components'] ?? [];
        if (! is_array($components) || array_is_list($components)) {
            throw new InvalidArgumentException('OpenAPI components must be an object.');
        }

        $raw = $components['securitySchemes'] ?? [];
        if (! is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException('OpenAPI securitySchemes must be an object.');
        }

        ksort($raw, SORT_STRING);
        $result = [];

        foreach ($raw as $name => $scheme) {
            if (! is_string($name) || ! is_array($scheme)) {
                continue;
            }

            $scopes = [];
            $flows = $scheme['flows'] ?? [];
            if (is_array($flows)) {
                foreach ($flows as $flow) {
                    if (! is_array($flow) || ! is_array($flow['scopes'] ?? null)) {
                        continue;
                    }

                    foreach (array_keys($flow['scopes']) as $scope) {
                        if (is_string($scope)) {
                            $scopes[] = $scope;
                        }
                    }
                }
            }

            $scopes = array_values(array_unique($scopes));
            sort($scopes, SORT_STRING);

            $result[$name] = [
                'type' => is_string($scheme['type'] ?? null) ? $scheme['type'] : 'unknown',
                'scheme' => is_string($scheme['scheme'] ?? null) ? $scheme['scheme'] : null,
                'in' => is_string($scheme['in'] ?? null) ? $scheme['in'] : null,
                'bearer_format' => is_string($scheme['bearerFormat'] ?? null) ? $scheme['bearerFormat'] : null,
                'scopes' => $scopes,
                'authority_granted' => false,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function servers(array $document): array
    {
        $servers = [];
        $rawServers = $document['servers'] ?? [];
        if (! is_array($rawServers)) {
            throw new InvalidArgumentException('OpenAPI servers must be an array.');
        }

        foreach ($rawServers as $server) {
            if (! is_array($server) || ! is_string($server['url'] ?? null)) {
                continue;
            }

            $url = trim($server['url']);
            if ($url !== '' && strlen($url) <= 512) {
                $servers[] = $url;
            }
        }

        $servers = array_values(array_unique($servers));
        sort($servers, SORT_STRING);

        return $servers;
    }

    /** @param list<string> $servers
     * @return list<string>
     */
    private function serverSemantics(array $servers): array
    {
        $unknown = [];

        foreach ($servers as $server) {
            if (! str_starts_with(strtolower($server), 'https://')) {
                $unknown[] = 'server_scheme_not_https:'.$server;
            }
        }

        return $unknown;
    }

    /**
     * @param mixed $security
     * @param array<string, array<string, mixed>> $knownSchemes
     * @return array{0:list<string>,1:array<string,list<string>>,2:list<string>}
     */
    private function security(mixed $security, array $knownSchemes): array
    {
        if (! is_array($security)) {
            return [[], [], ['security:unknown_shape']];
        }

        $schemes = [];
        $scopes = [];
        $unknown = [];

        foreach ($security as $requirement) {
            if (! is_array($requirement)) {
                $unknown[] = 'security:unknown_requirement';
                continue;
            }

            foreach ($requirement as $scheme => $requiredScopes) {
                if (! is_string($scheme)) {
                    continue;
                }

                $schemes[] = $scheme;
                if (! array_key_exists($scheme, $knownSchemes)) {
                    $unknown[] = 'security_scheme_unknown:'.$scheme;
                }

                $scopeList = [];
                if (is_array($requiredScopes)) {
                    foreach ($requiredScopes as $scope) {
                        if (is_string($scope)) {
                            $scopeList[] = $scope;
                        }
                    }
                }

                $scopeList = array_values(array_unique($scopeList));
                sort($scopeList, SORT_STRING);
                $scopes[$scheme] = $scopeList;
            }
        }

        $schemes = array_values(array_unique($schemes));
        sort($schemes, SORT_STRING);
        ksort($scopes, SORT_STRING);

        return [$schemes, $scopes, array_values(array_unique($unknown))];
    }

    /**
     * @param array<string, mixed> ...$levels
     * @return array<string, mixed>
     */
    private function declaredLimits(array ...$levels): array
    {
        $limits = [];

        foreach ($levels as $level) {
            foreach ($level as $key => $value) {
                if (! is_string($key) || preg_match('/^x-(?:rate|quota|limit)/i', $key) !== 1) {
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $limits[$key] = $value;
                }
            }
        }

        ksort($limits, SORT_STRING);

        return $limits;
    }
}
