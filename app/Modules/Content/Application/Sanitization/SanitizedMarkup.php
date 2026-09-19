<?php

namespace App\Modules\Content\Application\Sanitization;

use App\Modules\Content\Domain\Authoring\AuthoringTarget;
use InvalidArgumentException;

final readonly class SanitizedMarkup
{
    public const string POLICY_VERSION = 'vsn-html-policy-v1';

    public string $sha256;

    public function __construct(
        public AuthoringTarget $target,
        public string $markup,
        public string $policyVersion = self::POLICY_VERSION,
    ) {
        if (trim($this->policyVersion) === '') {
            throw new InvalidArgumentException('Sanitizer policy version must not be empty.');
        }

        $this->sha256 = hash('sha256', $this->markup);
    }

    /**
     * @return array{target: string, policy_version: string, sha256: string}
     */
    public function evidence(): array
    {
        return [
            'target' => $this->target->value,
            'policy_version' => $this->policyVersion,
            'sha256' => $this->sha256,
        ];
    }
}
