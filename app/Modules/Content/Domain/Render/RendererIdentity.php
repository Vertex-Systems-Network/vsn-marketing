<?php

namespace App\Modules\Content\Domain\Render;

use InvalidArgumentException;

final readonly class RendererIdentity
{
    public function __construct(
        public string $id,
        public string $version,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/i', $this->id) !== 1) {
            throw new InvalidArgumentException('Renderer id must be a bounded stable identifier.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9.+_-]{0,127}$/i', $this->version) !== 1) {
            throw new InvalidArgumentException('Renderer version must be a bounded stable identifier.');
        }
    }

    /** @return array{id: string, version: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
        ];
    }
}
