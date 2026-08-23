<?php

namespace App\Modules\Consent\Domain;

use InvalidArgumentException;

final class ConsentValueNormalizer
{
    public function channel(string $value): string
    {
        return $this->normalize($value, 64, 'Consent channel');
    }

    public function purpose(string $value): string
    {
        return $this->normalize($value, 120, 'Consent purpose');
    }

    public function source(string $value): string
    {
        return $this->normalize($value, 120, 'Consent source');
    }

    private function normalize(string $value, int $maxLength, string $label): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException($label.' is too long.');
        }

        return $value;
    }
}
