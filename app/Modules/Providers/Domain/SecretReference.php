<?php

namespace App\Modules\Providers\Domain;

use InvalidArgumentException;

final readonly class SecretReference
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (! preg_match('/^(vault|aws-secrets|gcp-secrets|azure-keyvault|env|secret):\/\/[A-Za-z0-9._\/:\-]+$/', $value)) {
            throw new InvalidArgumentException('Provider credentials must be stored as an approved secret reference.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
