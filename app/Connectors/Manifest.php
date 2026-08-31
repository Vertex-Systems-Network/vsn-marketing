<?php

declare(strict_types=1);

namespace App\Connectors;

class Manifest
{
    /** @var array */
    public array $capabilities = [];

    /** @var array */
    public array $provenance = [];

    public function __construct(array $capabilities = [], array $provenance = [])
    {
        $this->capabilities = $capabilities;
        $this->provenance = $provenance;
    }

    public function toArray(): array
    {
        return [
            'capabilities' => $this->capabilities,
            'provenance' => $this->provenance,
        ];
    }
}
