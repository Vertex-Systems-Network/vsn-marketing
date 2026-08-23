<?php

namespace App\Modules\Contacts\Domain;

use InvalidArgumentException;

final class ContactIdentityNormalizer
{
    public function normalize(
        ContactIdentityType $type,
        string $value,
        ?string $provider = null,
        ?string $providerReference = null,
    ): NormalizedContactIdentity {
        $value = trim($value);
        $provider = $this->normalizeNullable($provider, true);
        $providerReference = $this->normalizeNullable($providerReference);

        if ($value === '') {
            throw new InvalidArgumentException('Contact identity value cannot be empty.');
        }

        if (($provider === null) !== ($providerReference === null)) {
            throw new InvalidArgumentException('Provider and provider reference must be supplied together.');
        }

        $normalizedValue = match ($type) {
            ContactIdentityType::Email => $this->normalizeEmail($value),
            ContactIdentityType::Phone => $this->normalizePhone($value),
            ContactIdentityType::External => $this->normalizeExternal($value, $provider, $providerReference),
        };

        return new NormalizedContactIdentity(
            type: $type,
            value: $value,
            normalizedValue: $normalizedValue,
            provider: $provider,
            providerReference: $providerReference,
        );
    }

    private function normalizeEmail(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Contact email identity is invalid.');
        }

        return strtolower($value);
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === null || strlen($digits) < 7 || strlen($digits) > 15) {
            throw new InvalidArgumentException('Contact phone identity must contain 7 to 15 digits.');
        }

        return $digits;
    }

    private function normalizeExternal(string $value, ?string $provider, ?string $providerReference): string
    {
        if ($provider === null || $providerReference === null) {
            throw new InvalidArgumentException('External identities require a provider and provider reference.');
        }

        if ($value !== $providerReference) {
            throw new InvalidArgumentException('External identity value must equal its provider reference.');
        }

        return $provider.':'.$providerReference;
    }

    private function normalizeNullable(?string $value, bool $lowercase = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $lowercase ? strtolower($value) : $value;
    }
}
