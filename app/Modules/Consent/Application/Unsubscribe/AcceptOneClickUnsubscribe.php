<?php

namespace App\Modules\Consent\Application\Unsubscribe;

use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\Rfc8058Headers;
use DateTimeImmutable;
use InvalidArgumentException;

final class AcceptOneClickUnsubscribe
{
    public function handle(
        OpaqueUnsubscribeToken $token,
        string $method,
        string $body,
        DateTimeImmutable $acceptedAt,
    ): AcceptedOneClickUnsubscribe {
        if (strtoupper(trim($method)) !== 'POST') {
            throw new InvalidArgumentException('RFC 8058 one-click unsubscribe requires POST.');
        }

        parse_str($body, $fields);
        if (($fields['List-Unsubscribe'] ?? null) !== 'One-Click' || count($fields) !== 1) {
            throw new InvalidArgumentException('RFC 8058 POST body must be exactly List-Unsubscribe=One-Click.');
        }

        if ($token->isExpiredAt($acceptedAt)) {
            throw new InvalidArgumentException('One-click unsubscribe token is expired.');
        }

        return new AcceptedOneClickUnsubscribe(
            scope: $token->scope,
            tokenDigest: $token->digest,
            idempotencyKey: 'rfc8058:'.$token->digest,
            acceptedAt: $acceptedAt,
        );
    }

    public function expectedPostBody(): string
    {
        return Rfc8058Headers::ONE_CLICK_POST_VALUE;
    }
}
