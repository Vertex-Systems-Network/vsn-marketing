<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;

final readonly class ConnectorSourceProvenance
{
    public function __construct(
        public string $workspaceId,
        public string $sourceUri,
        public string $mediaType,
        public string $retrievedAt,
        public ?string $declaredVersion,
        public string $rawSha256,
        public string $normalizedSha256,
        public string $parserVersion,
    ) {
        if (trim($workspaceId) === '' || trim($sourceUri) === '') {
            throw new InvalidArgumentException('Connector source provenance requires workspace and source identity.');
        }

        foreach ([$rawSha256, $normalizedSha256] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
                throw new InvalidArgumentException('Connector source provenance requires SHA-256 identities.');
            }
        }

        $parsed = date_create_immutable($retrievedAt);
        if ($parsed === false || $parsed->format('P') !== '+00:00') {
            throw new InvalidArgumentException('Connector source retrieval time must be an explicit UTC timestamp.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'source_uri' => $this->sourceUri,
            'media_type' => $this->mediaType,
            'retrieved_at' => $this->retrievedAt,
            'declared_version' => $this->declaredVersion,
            'raw_sha256' => $this->rawSha256,
            'normalized_sha256' => $this->normalizedSha256,
            'parser_version' => $this->parserVersion,
        ];
    }
}
