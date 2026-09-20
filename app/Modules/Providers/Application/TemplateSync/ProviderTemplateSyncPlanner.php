<?php

namespace App\Modules\Providers\Application\TemplateSync;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateDrift;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncAction;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final class ProviderTemplateSyncPlanner
{
    /**
     * @throws JsonException
     */
    public function plan(
        ProviderTemplateSyncRequest $request,
        ?ProviderTemplateMapping $mapping,
        ProviderCapability $capability,
        ?ProviderTemplateObservation $observation,
        DateTimeImmutable $at,
    ): ProviderTemplateSyncPlan {
        self::assertCapabilityIdentity($request, $capability);
        self::assertPublicCapabilityConstraints($capability->constraints, 'constraints');

        if ($mapping !== null) {
            self::assertMappingIdentity($request, $mapping);
        }

        if ($observation !== null) {
            self::assertObservationIdentity($request, $mapping, $observation);
        }

        $capabilityReason = $this->capabilityUnavailableReason($request, $capability, $at);
        $providerTemplateReference = $mapping?->providerTemplateReference;

        $desiredDerivativeIdentity = $this->hash([
            'schema_version' => 1,
            'canonical' => $request->derivativeInput(),
            'provider' => [
                'provider_id' => $request->providerId,
                'provider_template_reference' => $providerTemplateReference,
                'capability_operation' => $capability->operation,
                'capability_source_version' => $capability->sourceVersion,
                'capability_constraints' => $this->normalizedCapabilityConstraints($capability->constraints),
            ],
        ]);

        [$drift, $action, $reason] = $this->reconcile(
            request: $request,
            mapping: $mapping,
            observation: $observation,
            desiredDerivativeIdentity: $desiredDerivativeIdentity,
            capabilityReason: $capabilityReason,
        );

        $requestIdentity = $this->hash([
            'request' => $request->toArray(),
            'mapping' => $mapping?->toArray(),
            'capability' => [
                'operation' => $capability->operation,
                'support' => $capability->support->value,
                'source_version' => $capability->sourceVersion,
                'observed_at' => $capability->observedAt->format(DATE_ATOM),
                'fresh_until' => $capability->freshUntil?->format(DATE_ATOM),
                'constraints' => $this->normalizedCapabilityConstraints($capability->constraints),
            ],
            'observation' => $observation?->toArray(),
            'desired_derivative_identity' => $desiredDerivativeIdentity,
        ]);

        return new ProviderTemplateSyncPlan(
            workspaceId: $request->workspaceId,
            providerId: $request->providerId,
            canonicalTemplateId: $request->canonicalTemplateId,
            canonicalTemplateVersionId: $request->canonicalTemplateVersionId,
            providerTemplateReference: $providerTemplateReference,
            desiredDerivativeIdentity: $desiredDerivativeIdentity,
            requestIdentity: $requestIdentity,
            idempotencyKey: $request->idempotencyKey,
            drift: $drift,
            action: $action,
            reason: $reason,
            capabilitySourceVersion: $capability->sourceVersion,
        );
    }

    /**
     * @return array{ProviderTemplateDrift, ProviderTemplateSyncAction, string}
     */
    private function reconcile(
        ProviderTemplateSyncRequest $request,
        ?ProviderTemplateMapping $mapping,
        ?ProviderTemplateObservation $observation,
        string $desiredDerivativeIdentity,
        ?string $capabilityReason,
    ): array {
        if ($mapping === null) {
            return [
                ProviderTemplateDrift::MissingMapping,
                ProviderTemplateSyncAction::Blocked,
                'Provider template mapping is missing.',
            ];
        }

        if ($capabilityReason !== null) {
            return [
                ProviderTemplateDrift::CapabilityUnavailable,
                ProviderTemplateSyncAction::UseFallback,
                $capabilityReason,
            ];
        }

        if ($observation === null) {
            return [
                ProviderTemplateDrift::ProviderTemplateMissing,
                ProviderTemplateSyncAction::Synchronize,
                'Mapped provider template is unavailable and requires deterministic recreation.',
            ];
        }

        if (
            $mapping->lastObservedProviderFingerprint !== null
            && strtolower($observation->providerFingerprint) !== strtolower($mapping->lastObservedProviderFingerprint)
        ) {
            return [
                ProviderTemplateDrift::ExternalProviderChange,
                ProviderTemplateSyncAction::ReviewConflict,
                'Provider template changed externally since the last synchronized observation.',
            ];
        }

        if (
            $mapping->lastSyncedCanonicalVersionId !== $request->canonicalTemplateVersionId
            || strtolower($mapping->lastSyncedDerivativeIdentity ?? '') !== strtolower($desiredDerivativeIdentity)
        ) {
            return [
                ProviderTemplateDrift::CanonicalVersionAdvanced,
                ProviderTemplateSyncAction::Synchronize,
                'Canonical VSN template inputs changed and require a new provider derivative.',
            ];
        }

        return [
            ProviderTemplateDrift::InSync,
            ProviderTemplateSyncAction::None,
            'Provider template derivative matches the pinned canonical VSN inputs.',
        ];
    }

    private function capabilityUnavailableReason(
        ProviderTemplateSyncRequest $request,
        ProviderCapability $capability,
        DateTimeImmutable $at,
    ): ?string {
        if ($capability->support !== CapabilitySupport::Supported) {
            return 'Provider template synchronization capability is not supported.';
        }

        if ($capability->sourceVersion === null || trim($capability->sourceVersion) === '') {
            return 'Provider template synchronization capability lacks explicit source version evidence.';
        }

        if ($capability->observedAt > $at) {
            return 'Provider template synchronization capability is not effective yet.';
        }

        if ($capability->freshUntil !== null && $capability->freshUntil < $at) {
            return 'Provider template synchronization capability evidence is stale.';
        }

        $templateKinds = $capability->constraints['template_kinds'] ?? null;
        if (is_array($templateKinds) && in_array($request->templateKind, $templateKinds, true) === false) {
            return "Provider capability does not support template kind {$request->templateKind}.";
        }

        $mediaKinds = $capability->constraints['media_kinds'] ?? null;
        if (is_array($mediaKinds)) {
            foreach ($request->mediaKinds as $mediaKind) {
                if (in_array($mediaKind, $mediaKinds, true) === false) {
                    return "Provider capability does not support media kind {$mediaKind}.";
                }
            }
        }

        return null;
    }

    private static function assertCapabilityIdentity(
        ProviderTemplateSyncRequest $request,
        ProviderCapability $capability,
    ): void {
        if ($capability->workspaceId !== $request->workspaceId || $capability->providerId !== $request->providerId) {
            throw new InvalidArgumentException('Provider template capability cannot cross workspace or provider boundaries.');
        }

        if ($capability->operation !== 'template.sync') {
            throw new InvalidArgumentException('Provider template synchronization requires template.sync capability evidence.');
        }
    }

    private static function assertMappingIdentity(
        ProviderTemplateSyncRequest $request,
        ProviderTemplateMapping $mapping,
    ): void {
        if (
            $mapping->workspaceId !== $request->workspaceId
            || $mapping->providerId !== $request->providerId
            || $mapping->canonicalTemplateId !== $request->canonicalTemplateId
        ) {
            throw new InvalidArgumentException('Provider template mapping does not match the requested workspace/provider/canonical template.');
        }
    }

    private static function assertObservationIdentity(
        ProviderTemplateSyncRequest $request,
        ?ProviderTemplateMapping $mapping,
        ProviderTemplateObservation $observation,
    ): void {
        if ($mapping === null) {
            throw new InvalidArgumentException('Provider template observation cannot be reconciled without an explicit mapping.');
        }

        if (
            $observation->workspaceId !== $request->workspaceId
            || $observation->providerId !== $request->providerId
            || $observation->providerTemplateReference !== $mapping->providerTemplateReference
        ) {
            throw new InvalidArgumentException('Provider template observation does not match the explicit mapping boundary.');
        }
    }

    private static function assertPublicCapabilityConstraints(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException("Provider template capability number must be finite: {$path}");
            }

            return;
        }

        if (is_string($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $segment = (string) $key;

                if (
                    is_string($key)
                    && preg_match(
                        '/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key|upload[_-]?id|provider[_-]?asset[_-]?id/i',
                        $key,
                    ) === 1
                ) {
                    throw new InvalidArgumentException("Sensitive provider template capability key is forbidden: {$path}.{$segment}");
                }

                self::assertPublicCapabilityConstraints($nested, $path.'.'.$segment);
            }

            return;
        }

        throw new InvalidArgumentException("Provider template capability constraints must be JSON-compatible: {$path}");
    }

    /** @param array<string, mixed> $constraints @return array<string, mixed> */
    private function normalizedCapabilityConstraints(array $constraints): array
    {
        foreach (['template_kinds', 'media_kinds'] as $key) {
            if (isset($constraints[$key]) && is_array($constraints[$key])) {
                $values = $constraints[$key];
                sort($values, SORT_STRING);
                $constraints[$key] = $values;
            }
        }

        return $constraints;
    }

    /**
     * @throws JsonException
     */
    private function hash(mixed $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException('Provider template canonical payload numbers must be finite.');
            }

            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item);
            }

            return $value;
        }

        throw new InvalidArgumentException('Provider template canonical payload must be JSON-compatible.');
    }
}
