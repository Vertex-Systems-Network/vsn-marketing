<?php

namespace App\Modules\Assets\Infrastructure\Storage;

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final class WorkspaceAssetObjectStore
{
    public const int MAX_OBJECT_BYTES = 104_857_600;

    public function __construct(
        private readonly ObjectStore $objects,
    ) {}

    public function putImmutable(
        string $workspaceId,
        string $storageKey,
        string $contents,
        string $expectedSha256,
    ): void {
        $this->assertWorkspaceKey($workspaceId, $storageKey);

        $byteSize = strlen($contents);
        if ($byteSize < 1 || $byteSize > self::MAX_OBJECT_BYTES) {
            throw new InvalidArgumentException('Asset object size is outside the allowed storage boundary.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new InvalidArgumentException('Asset object expected SHA-256 must be normalized lowercase hex.');
        }

        $actualSha256 = hash('sha256', $contents);
        if (! hash_equals($expectedSha256, $actualSha256)) {
            throw new InvalidArgumentException('Asset object content hash does not match the expected SHA-256.');
        }

        if ($this->objects->exists($storageKey)) {
            $existing = $this->objects->get($storageKey);
            if (! hash_equals($expectedSha256, hash('sha256', $existing))) {
                throw new RuntimeException('Immutable asset object key already contains different content.');
            }

            return;
        }

        $this->objects->put($storageKey, $contents, ['visibility' => 'private']);
    }

    public function getVerified(string $workspaceId, string $storageKey, string $expectedSha256): string
    {
        $this->assertWorkspaceKey($workspaceId, $storageKey);

        if (! $this->objects->exists($storageKey)) {
            throw new RuntimeException('Canonical asset object does not exist.');
        }

        $contents = $this->objects->get($storageKey);
        if (! hash_equals($expectedSha256, hash('sha256', $contents))) {
            throw new RuntimeException('Canonical asset object hash verification failed.');
        }

        return $contents;
    }

    public function exists(string $workspaceId, string $storageKey): bool
    {
        $this->assertWorkspaceKey($workspaceId, $storageKey);

        return $this->objects->exists($storageKey);
    }

    private function assertWorkspaceKey(string $workspaceId, string $storageKey): void
    {
        if (trim($workspaceId) === '') {
            throw new InvalidArgumentException('Asset storage workspace id must not be empty.');
        }

        if (
            trim($storageKey) === ''
            || str_starts_with($storageKey, '/')
            || str_contains($storageKey, '\\')
            || str_contains($storageKey, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $storageKey) === 1
        ) {
            throw new InvalidArgumentException('Asset storage key contains an unsafe path.');
        }

        $requiredPrefix = 'workspaces/'.$workspaceId.'/assets/';
        if (! str_starts_with($storageKey, $requiredPrefix)) {
            throw new AuthorizationException('Asset storage key is outside the requested workspace boundary.');
        }
    }
}
