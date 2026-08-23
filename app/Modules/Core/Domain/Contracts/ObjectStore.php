<?php

namespace App\Modules\Core\Domain\Contracts;

interface ObjectStore
{
    public function put(string $path, string $contents, array $options = []): void;

    public function get(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
