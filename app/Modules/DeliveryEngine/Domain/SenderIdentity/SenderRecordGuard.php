<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

use InvalidArgumentException;

final class SenderRecordGuard
{
    /** @var list<string> */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'password',
        'secret',
        'private_key',
        'signing_key',
        'api_key',
        'access_token',
        'refresh_token',
        'credential',
        'authorization',
    ];

    public static function assertNoSecretMaterial(array $payload, string $path = 'payload'): void
    {
        foreach ($payload as $key => $value) {
            $currentPath = $path.'.'.(string) $key;
            $normalizedKey = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($normalizedKey, $fragment)) {
                    throw new InvalidArgumentException('Canonical sender records must not contain credential or private signing material at '.$currentPath.'.');
                }
            }

            if (is_array($value)) {
                self::assertNoSecretMaterial($value, $currentPath);

                continue;
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Canonical sender metadata must be JSON-safe at '.$currentPath.'.');
            }

            if (is_string($value) && preg_match('/-----BEGIN (?:ENCRYPTED )?(?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $value) === 1) {
                throw new InvalidArgumentException('Canonical sender records must not contain private key material at '.$currentPath.'.');
            }
        }
    }
}
