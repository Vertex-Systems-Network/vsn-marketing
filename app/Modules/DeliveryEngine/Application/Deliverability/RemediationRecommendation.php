<?php

namespace App\Modules\DeliveryEngine\Application\Deliverability;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RemediationRecommendation
{
    public const EXECUTION_MODE_PROPOSAL_ONLY = 'proposal_only';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    private const ALLOWED_RISKS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    private const ALLOWED_SCOPES = [
        'evidence',
        'monitoring',
        'dns',
        'sender_identity',
        'routing',
        'provider',
        'suppression',
        'frequency',
        'provider_policy',
    ];

    private const RISKY_SCOPES = [
        'dns',
        'sender_identity',
        'routing',
        'provider',
        'suppression',
        'frequency',
        'provider_policy',
    ];

    private const FORBIDDEN_CODE_FRAGMENTS = [
        'override_suppression',
        'bypass_frequency',
        'create_consent',
        'create_authorization',
        'rotate_account',
        'deceptive_header',
        'evade_provider',
        'circumvent_limit',
    ];

    /**
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $workspaceId,
        public string $providerKey,
        public string $messagePurpose,
        public string $scope,
        public string $rationale,
        public array $evidenceIds,
        public string $riskLevel,
        public bool $requiresHumanApproval,
        public bool $requiresPolicyApproval,
        public string $executionMode,
        public DateTimeImmutable $recommendedAt,
    ) {
        foreach ([
            'id' => $id,
            'code' => $code,
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
            'messagePurpose' => $messagePurpose,
            'scope' => $scope,
            'rationale' => $rationale,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if (! in_array($riskLevel, self::ALLOWED_RISKS, true)) {
            throw new InvalidArgumentException('Unsupported remediation risk level.');
        }

        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException('Unsupported remediation scope.');
        }

        if ($executionMode !== self::EXECUTION_MODE_PROPOSAL_ONLY) {
            throw new InvalidArgumentException('Remediation recommendations must remain proposal-only.');
        }

        foreach ($evidenceIds as $evidenceId) {
            if (! is_string($evidenceId) || trim($evidenceId) === '') {
                throw new InvalidArgumentException('evidenceIds must contain only non-blank strings.');
            }
        }

        if (count(array_unique($evidenceIds)) !== count($evidenceIds)) {
            throw new InvalidArgumentException('evidenceIds must be unique.');
        }

        $normalizedCode = strtolower($code);

        foreach (self::FORBIDDEN_CODE_FRAGMENTS as $fragment) {
            if (str_contains($normalizedCode, $fragment)) {
                throw new InvalidArgumentException('Unsafe remediation recommendation code is forbidden.');
            }
        }

        $riskRequiresApproval = in_array($scope, self::RISKY_SCOPES, true)
            || in_array($riskLevel, [self::RISK_HIGH, self::RISK_CRITICAL], true);

        if ($riskRequiresApproval && (! $requiresHumanApproval || ! $requiresPolicyApproval)) {
            throw new InvalidArgumentException('Risky remediation proposals require human and policy approval.');
        }
    }
}
