<?php

namespace App\Modules\Core\Infrastructure\Storage;

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final readonly class LaravelObjectStore implements ObjectStore
{
    public function __construct(private FilesystemManager $filesystems, private string $disk) {}
    public function put(string $path, string $contents): void
    {
        if (! $this->filesystems->disk($this->disk)->put($path, $contents)) {
            throw new RuntimeException("Unable to write object [{$path}] to disk [{$this->disk}].");
        }
    }
    public function get(string $path): string
    {
        $contents = $this->filesystems->disk($this->disk)->get($path);
        if (! is_string($contents)) {
            throw new RuntimeException("Object [{$path}] on disk [{$this->disk}] did not contain string data.");
        }
        return $contents;
    }
    public function exists(string $path): bool { return $this->filesystems->disk($this->disk)->exists($path); }
    public function delete(string $path): void
    {
        if (! $this->filesystems->disk($this->disk)->delete($path)) {
            throw new RuntimeException("Unable to delete object [{$path}] from disk [{$this->disk}].");
        }
    }
}
