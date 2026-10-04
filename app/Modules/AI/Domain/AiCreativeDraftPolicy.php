<?php

namespace App\Modules\AI\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final class AiCreativeDraftPolicy
{
    public function validate(array $result, array $definition, TenantContext $scope, array $references, string $rightsReference, string $contextHash): array
    {
        $output = $result['output'] ?? null;
        $provenance = $result['provider_request_id'] ?? null;
        if (($result['status'] ?? null) !== 'complete' || ($result['schema_id'] ?? null) !== $definition['output_schema_id']
            || ! is_string($provenance) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $provenance)
            || ! is_array($output) || array_diff(array_keys($output), ['workspace_id', 'reference_ids', 'text', 'media']) !== []
            || ($output['workspace_id'] ?? null) !== $scope->workspaceId
            || ! is_array($output['reference_ids'] ?? null) || ! array_is_list($output['reference_ids'])
            || count($output['reference_ids']) > 16 || ! is_string($output['text'] ?? null)
            || ! (new AiContextSanitizer)->safe($output['text']) || strlen($output['text']) > $definition['max_text_bytes']
            || preg_match('#https?://|VSN_INTERNAL_|<script\b#i', $output['text'])
            || ! is_array($output['media'] ?? null) || ! array_is_list($output['media'])) {
            throw new InvalidArgumentException('Creative output/provenance rejected.');
        }
        foreach ($output['reference_ids'] as $reference) {
            if (! is_string($reference) || ! in_array($reference, $references, true)) {
                throw new InvalidArgumentException('Creative source reference rejected.');
            }
        }
        if (count($output['reference_ids']) !== count(array_unique($output['reference_ids']))
            || count($output['media']) !== ($definition['id'] === 'creative_image' ? 1 : 0)) {
            throw new InvalidArgumentException('Creative media/reference bound rejected.');
        }
        $media = [];
        foreach ($output['media'] as $asset) {
            if (! is_array($asset) || array_diff(array_keys($asset), ['mime_type', 'bytes']) !== []
                || ! is_string($asset['bytes'] ?? null) || $asset['bytes'] === ''
                || strlen($asset['bytes']) > $definition['max_media_bytes']
                || ! in_array($asset['mime_type'] ?? null, $definition['media_mime_types'], true)) {
                throw new InvalidArgumentException('Creative media type/size rejected.');
            }
            if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($asset['bytes']) !== $asset['mime_type']) {
                throw new InvalidArgumentException('Creative media MIME inspection rejected.');
            }
            // Convert parser notices into a generic denial without logging binary content.
            set_error_handler(static function (): never {
                throw new InvalidArgumentException('Creative media parsing rejected.');
            });
            try {
                $dimensions = getimagesizefromstring($asset['bytes']);
            } finally {
                restore_error_handler();
            }
            if ($dimensions === false || $dimensions['mime'] !== $asset['mime_type']
                || $dimensions[0] < 1 || $dimensions[1] < 1
                || $dimensions[0] > $definition['max_dimension'] || $dimensions[1] > $definition['max_dimension']) {
                throw new InvalidArgumentException('Creative media header rejected.');
            }
            $media[] = ['mime_type' => $asset['mime_type'], 'bytes_base64' => base64_encode($asset['bytes']),
                'sha256' => hash('sha256', $asset['bytes']), 'byte_size' => strlen($asset['bytes']),
                'width' => $dimensions[0], 'height' => $dimensions[1]];
        }
        $draft = ['status' => 'draft', 'review_status' => 'pending_independent_review',
            'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId, 'created_by_actor_id' => $scope->actorId,
            'capability' => $definition['id'], 'policy_version' => $definition['version'],
            'policy_sha256' => $definition['policy_sha256'], 'context_manifest_sha256' => $contextHash,
            'reference_ids' => $output['reference_ids'], 'rights_reference' => $rightsReference,
            'provider_request_id' => $provenance, 'text' => $output['text'], 'media' => $media,
            'disclosure' => $definition['disclosure'], 'content_credentials' => 'unverified',
            'evidence_kind' => 'offline_contract'];

        return $draft + ['candidate_sha256' => self::hash($draft)];
    }

    public static function hash(array $draft): string
    {
        unset($draft['candidate_sha256']);

        return hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR));
    }
}
