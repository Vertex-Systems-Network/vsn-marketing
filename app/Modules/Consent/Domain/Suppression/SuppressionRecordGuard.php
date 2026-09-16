<?php

namespace App\Modules\Consent\Domain\Suppression;

use InvalidArgumentException;

final class SuppressionRecordGuard
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
        'cookie',
        'raw_email',
        'recipient_email',
        'email_address',
        'phone_number',
    ];

    public static function assertSafe(array $payload, string $path = 'payload'): void
    {
        foreach ($payload as $key => $value) {
            $currentPath = $path.'.'.(string) $key;
            $normalizedKey = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($normalizedKey, $fragment)) {
                    throw new InvalidArgumentException('Suppression audit payload contains secret or direct-PII material at '.$currentPath.'.');
                }
            }

            if (is_array($value)) {
                self::assertSafe($value, $currentPath);

                continue;
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Suppression audit payload must be JSON-safe at '.$currentPath.'.');
            }

            if (is_string($value) && preg_match('/-----BEGIN (?:ENCRYPTED )?(?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $value) === 1) {
                throw new InvalidArgumentException('Suppression audit payload must not contain private key material at '.$currentPath.'.');
            }
        }
    }
}
