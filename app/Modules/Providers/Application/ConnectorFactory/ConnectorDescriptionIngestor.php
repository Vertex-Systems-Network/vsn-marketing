<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorSourceProvenance;
use App\Modules\Providers\Domain\ConnectorFactory\IngestedConnectorDescription;
use InvalidArgumentException;
use JsonException;

final class ConnectorDescriptionIngestor
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_DEPTH = 32;

    public const MAX_NODES = 20_000;

    public const PARSER_VERSION = 'task0083-v1';

    /** @var list<string> */
    private const JSON_MEDIA_TYPES = [
        'application/json',
        'application/openapi+json',
        'application/vnd.oai.openapi+json',
    ];

    /** @var list<string> */
    private const TEXT_MEDIA_TYPES = [
        'text/plain',
        'text/markdown',
    ];

    public function ingest(
        string $workspaceId,
        string $sourceUri,
        string $mediaType,
        string $contents,
        string $retrievedAt,
        ?string $declaredVersion = null,
    ): IngestedConnectorDescription {
        $mediaType = strtolower(trim(explode(';', $mediaType, 2)[0]));

        if (trim($workspaceId) === '' || trim($sourceUri) === '') {
            throw new InvalidArgumentException('Connector ingestion requires workspace and source identity.');
        }

        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Connector source is empty or exceeds the bounded ingestion size.');
        }

        if (str_contains($contents, "\0")) {
            throw new InvalidArgumentException('Connector source contains forbidden binary content.');
        }

        $rawHash = hash('sha256', $contents);

        if (in_array($mediaType, self::JSON_MEDIA_TYPES, true)) {
            try {
                $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('OpenAPI JSON is malformed.', previous: $exception);
            }

            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidArgumentException('OpenAPI source must be a JSON object.');
            }

            $openApiVersion = $decoded['openapi'] ?? null;
            if (! is_string($openApiVersion) || ! preg_match('/^3\.[0-9]+(?:\.[0-9]+)?(?:[-+].*)?$/', $openApiVersion)) {
                throw new InvalidArgumentException('JSON connector descriptions must declare an OpenAPI 3.x version.');
            }

            $nodes = 0;
            $this->validateNode($decoded, 0, $nodes);
            $normalized = json_encode(
                $this->canonicalize($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            $provenance = new ConnectorSourceProvenance(
                workspaceId: $workspaceId,
                sourceUri: $sourceUri,
                mediaType: $mediaType,
                retrievedAt: $retrievedAt,
                declaredVersion: $declaredVersion ?? $openApiVersion,
                rawSha256: $rawHash,
                normalizedSha256: hash('sha256', $normalized),
                parserVersion: self::PARSER_VERSION,
            );

            return new IngestedConnectorDescription($provenance, 'openapi', $decoded, $normalized);
        }

        if (in_array($mediaType, self::TEXT_MEDIA_TYPES, true)) {
            $this->rejectActiveContent($contents);
            $normalized = $this->normalizeText($contents);

            $provenance = new ConnectorSourceProvenance(
                workspaceId: $workspaceId,
                sourceUri: $sourceUri,
                mediaType: $mediaType,
                retrievedAt: $retrievedAt,
                declaredVersion: $declaredVersion,
                rawSha256: $rawHash,
                normalizedSha256: hash('sha256', $normalized),
                parserVersion: self::PARSER_VERSION,
            );

            return new IngestedConnectorDescription($provenance, 'documentation', null, $normalized);
        }

        throw new InvalidArgumentException('Unsupported connector source media type; unsupported formats fail closed.');
    }

    private function validateNode(mixed $value, int $depth, int &$nodes, ?string $key = null): void
    {
        $nodes++;

        if ($depth > self::MAX_DEPTH || $nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('Connector source exceeds bounded depth or node count.');
        }

        if (is_string($value)) {
            if ($key === '$ref' && ! str_starts_with($value, '#/')) {
                throw new InvalidArgumentException('External OpenAPI references are forbidden during TASK-0083 ingestion.');
            }

            $this->rejectActiveContent($value);

            if (strlen($value) > 65_536) {
                throw new InvalidArgumentException('Connector source contains an oversized scalar value.');
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $childKey => $child) {
            $this->validateNode($child, $depth + 1, $nodes, is_string($childKey) ? $childKey : null);
        }
    }

    private function rejectActiveContent(string $value): void
    {
        if (preg_match('/<\s*(script|iframe|object|embed)\b|javascript\s*:|data\s*:\s*text\/html|\bon[a-z]+\s*=/i', $value) === 1) {
            throw new InvalidArgumentException('Active or script-like connector documentation is forbidden.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $child): mixed => $this->canonicalize($child), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function normalizeText(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = array_map(static fn (string $line): string => rtrim($line), explode("\n", $contents));

        return trim(implode("\n", $lines))."\n";
    }
}
