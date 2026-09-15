<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

use InvalidArgumentException;
use Stringable;

final readonly class SenderDomainName implements Stringable
{
    public string $value;

    public function __construct(string $domain)
    {
        $canonical = strtolower(rtrim(trim($domain), '.'));

        if ($canonical === '' || strlen($canonical) > 253) {
            throw new InvalidArgumentException('Sender domain must contain between 1 and 253 characters.');
        }

        if (! str_contains($canonical, '.') || filter_var($canonical, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Sender domain must be a valid public hostname.');
        }

        $this->value = $canonical;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
