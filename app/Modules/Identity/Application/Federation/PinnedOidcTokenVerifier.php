<?php

namespace App\Modules\Identity\Application\Federation;

use Illuminate\Auth\Access\AuthorizationException;

/** Offline proof boundary: configuration and expected nonce come from trusted server state. */
final class PinnedOidcTokenVerifier
{
    /** @return array{issuer: string, audience: string, subject: string, issuedAt: int, expiresAt: int, replayId: string} */
    public function verify(string $token, string $issuer, string $clientId, string $nonce, string $publicKeyPem, int $now, ?string $expectedKeyId = null): array
    {
        $deny = static function (): never {
            throw new AuthorizationException('Federation assertion denied.');
        };
        if (strlen($token) > 16384 || $issuer === '' || ! str_starts_with($issuer, 'https://') || $clientId === '' || $nonce === '' || $now <= 0) {
            $deny();
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[2])) {
            $deny();
        }
        $decoded = [];
        foreach ($parts as $part) {
            $value = base64_decode(strtr($part, '-_', '+/'), true);
            if ($value === false || rtrim(strtr(base64_encode($value), '+/', '-_'), '=') !== $part) {
                $deny();
            }
            $decoded[] = $value;
        }
        try {
            $header = json_decode($decoded[0], false, 32, JSON_THROW_ON_ERROR);
            $claims = json_decode($decoded[1], false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $deny();
        }
        if (! is_object($header) || ! is_object($claims) || ($header->alg ?? null) !== 'RS256' ||
            (isset($header->typ) && ! in_array($header->typ, ['JWT', 'application/oidc-id-token'], true)) ||
            property_exists($header, 'jku') || property_exists($header, 'x5u') ||
            property_exists($header, 'jwk') || property_exists($header, 'x5c') ||
            isset($header->crit) || isset($header->enc) || isset($header->zip) ||
            ($expectedKeyId === null ? property_exists($header, 'kid') :
                ($expectedKeyId === '' || strlen($expectedKeyId) > 255 || ! is_string($header->kid ?? null) ||
                    ! hash_equals($expectedKeyId, $header->kid)))) {
            $deny();
        }
        $key = openssl_pkey_get_public($publicKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 ||
            openssl_verify($parts[0].'.'.$parts[1], $decoded[2], $key, OPENSSL_ALGO_SHA256) !== 1) {
            $deny();
        }
        $aud = $claims->aud ?? null;
        $audienceMatches = is_string($aud) ? hash_equals($clientId, $aud) :
            (is_array($aud) && count($aud) > 0 && count($aud) <= 8 && count($aud) === count(array_unique($aud, SORT_REGULAR)) &&
                count(array_filter($aud, 'is_string')) === count($aud) && in_array($clientId, $aud, true));
        if (! is_string($claims->iss ?? null) || ! hash_equals($issuer, $claims->iss) || ! $audienceMatches ||
            (property_exists($claims, 'azp') && (! is_string($claims->azp) || ! hash_equals($clientId, $claims->azp))) ||
            (is_array($aud) && count($aud) > 1 && ! property_exists($claims, 'azp')) ||
            ! is_string($claims->nonce ?? null) || ! hash_equals($nonce, $claims->nonce) ||
            ! is_string($claims->sub ?? null) || trim($claims->sub) === '' || strlen($claims->sub) > 255 ||
            ! is_int($claims->iat ?? null) || ! is_int($claims->exp ?? null) ||
            $claims->iat <= 0 || $claims->iat > $now || $claims->exp <= $now || $claims->exp - $claims->iat > 3600) {
            $deny();
        }

        return ['issuer' => $claims->iss, 'audience' => $clientId, 'subject' => $claims->sub,
            'issuedAt' => $claims->iat, 'expiresAt' => $claims->exp, 'replayId' => hash('sha256', $token)];
    }
}
