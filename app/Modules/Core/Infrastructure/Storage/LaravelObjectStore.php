<?php

namespace App\Modules\Core\Infrastructure\Storage;

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final class LaravelObjectStore implements ObjectStore
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly string $disk,
    ) {
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        if (! $this->filesystems->disk($this->disk)->put($path, $contents, $options)) {
            throw new RuntimeException("Unable to write object [{$path}] to disk [{$this->disk}].");
        }
    }

    public function get(string $path): string
    {
        return $this->filesystems->disk($this->disk)->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->filesystems->disk($this->disk)->exists($path);
    }

    public function delete(string $path): void
    {
        if (! $this->filesystems->disk($this->disk)->delete($path)) {
            throw new RuntimeException("Unable to delete object [{$path}] from disk [{$this->disk}].");
        }
    }
}
