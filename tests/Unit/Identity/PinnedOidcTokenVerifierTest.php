<?php

use App\Modules\Identity\Application\Federation\PinnedOidcTokenVerifier;
use Illuminate\Auth\Access\AuthorizationException;

function signedIdentityToken(array $header, array $claims, $privateKey): string
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $message = $encode($header).'.'.$encode($claims);
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

it('validates a pinned signed token and fails closed on altered identity and key metadata', function (): void {
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $public = openssl_pkey_get_details($private)['key'];
    $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $claims = ['iss' => 'https://idp.example.test', 'aud' => 'client', 'sub' => 'stable-subject',
        'nonce' => 'issued-nonce', 'iat' => 1000, 'exp' => 1100, 'email' => 'untrusted@example.test', 'roles' => ['owner']];
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $verify = app(PinnedOidcTokenVerifier::class);
    $check = static fn (string $token, ?string $key = null, string $nonce = 'issued-nonce') => $verify->verify($token, 'https://idp.example.test', 'client', $nonce, $key ?? $public, 1050);
    $token = signedIdentityToken($header, $claims, $private);
    expect($check($token)['subject'])->toBe('stable-subject');
    expect($check($token)['replayId'])->toBe(hash('sha256', $token));
    expect(fn () => $check($token, openssl_pkey_get_details($other)['key']))->toThrow(AuthorizationException::class);
    expect(fn () => $check($token, $public, 'different'))->toThrow(AuthorizationException::class);
    [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);
    $tamperedSignature = ($encodedSignature[0] === 'A' ? 'B' : 'A').substr($encodedSignature, 1);
    expect(fn () => $check($encodedHeader.'.'.$encodedClaims.'.'.$tamperedSignature))->toThrow(AuthorizationException::class);
    foreach ([['alg' => 'none'], ['alg' => 'HS256'], ['alg' => 'RS256', 'jku' => 'https://evil.test/key'],
        ['alg' => 'RS256', 'x5u' => 'https://evil.test/key'], ['alg' => 'RS256', 'kid' => 'attacker']] as $badHeader) {
        expect(fn () => $check(signedIdentityToken($badHeader, $claims, $private)))->toThrow(AuthorizationException::class);
    }
    foreach ([['iss' => 'https://other.test'], ['aud' => 'other'], ['sub' => ''], ['iat' => 1051],
        ['exp' => 1050], ['exp' => 5000], ['aud' => ['client', 'other']], ['azp' => 'other'],
        ['azp' => 7], ['nonce' => 'other']] as $change) {
        expect(fn () => $check(signedIdentityToken($header, array_replace($claims, $change), $private)))->toThrow(AuthorizationException::class);
    }
    $multi = array_replace($claims, ['aud' => ['client', 'other'], 'azp' => 'client']);
    expect($check(signedIdentityToken($header, $multi, $private))['audience'])->toBe('client');
    expect($check(signedIdentityToken($header, array_replace($claims, ['azp' => 'client']), $private))['audience'])->toBe('client');
});
