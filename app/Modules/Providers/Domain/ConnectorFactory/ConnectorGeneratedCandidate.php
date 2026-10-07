<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;

final readonly class ConnectorGeneratedCandidate
{
    /**
     * @param  list<array{path: string, content: string, sha256: string}>  $files
     */
    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public string $candidateId,
        public string $generatorVersion,
        public string $templateVersion,
        public string $toolchainVersion,
        public string $inputPlanSha256,
        public array $files,
        public bool $executable = false,
        public bool $authorityGranted = false,
        public string $activationState = 'candidate_only',
    ) {
        if (trim($workspaceId) === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('Generated connector candidate requires a workspace and safe provider key.');
        }

        foreach ([$candidateId, $inputPlanSha256] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Generated connector candidate requires SHA-256 identities.');
            }
        }

        if ($executable || $authorityGranted || $activationState !== 'candidate_only') {
            throw new InvalidArgumentException('Generated connector candidates must remain non-executable and authority-free.');
        }

        if (count($files) !== 3) {
            throw new InvalidArgumentException('Generated connector candidates must contain the fixed three-file artifact set.');
        }

        $paths = [];
        foreach ($files as $file) {
            if (! isset($file['path'], $file['content'], $file['sha256'])
                || ! is_string($file['path'])
                || ! is_string($file['content'])
                || ! is_string($file['sha256'])
                || ! str_starts_with($file['path'], 'connector-candidates/'.$providerKey.'/')
                || str_contains($file['path'], '..')
                || str_starts_with($file['path'], '/')
                || preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1
                || ! hash_equals(hash('sha256', $file['content']), $file['sha256'])) {
                throw new InvalidArgumentException('Generated connector candidate contains an invalid or out-of-root artifact.');
            }

            if (in_array($file['path'], $paths, true)) {
                throw new InvalidArgumentException('Generated connector candidate artifact paths must be unique.');
            }

            $paths[] = $file['path'];
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'candidate_id' => $this->candidateId,
            'generator_version' => $this->generatorVersion,
            'template_version' => $this->templateVersion,
            'toolchain_version' => $this->toolchainVersion,
            'input_plan_sha256' => $this->inputPlanSha256,
            'files' => $this->files,
            'executable' => $this->executable,
            'authority_granted' => $this->authorityGranted,
            'activation_state' => $this->activationState,
        ];
    }
}
