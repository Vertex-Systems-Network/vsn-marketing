<?php

use App\Modules\Consent\Application\Unsubscribe\AcceptOneClickUnsubscribe;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\Rfc8058Eligibility;
use App\Modules\Consent\Domain\Unsubscribe\Rfc8058Headers;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;

function task0027OneClickToken(?DateTimeImmutable $expiresAt = null): OpaqueUnsubscribeToken
{
    return new OpaqueUnsubscribeToken(
        value: str_repeat('a', 43),
        scope: new UnsubscribeScope(
            workspaceId: 'workspace-1',
            contactId: 'contact-1',
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'weekly',
        ),
        issuedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        expiresAt: $expiresAt,
    );
}

it('builds the required RFC8058 HTTPS headers without exposing scope identifiers', function () {
    $token = task0027OneClickToken();
    $headers = new Rfc8058Headers('https://example.test/unsubscribe/one-click', $token);

    expect($headers->asArray())
        ->toBe([
            'List-Unsubscribe' => '<https://example.test/unsubscribe/one-click?token='.$token->value.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ])
        ->and($headers->listUnsubscribe)->not->toContain('workspace-1')
        ->and($headers->listUnsubscribe)->not->toContain('contact-1');
});

it('requires marketing purpose valid dkim and both one-click headers to be covered', function () {
    expect((new Rfc8058Eligibility(
        messagePurpose: MessageIntentType::Marketing,
        dkimSignatureValid: true,
        dkimCoveredHeaders: ['From', 'List-Unsubscribe', 'List-Unsubscribe-Post'],
    ))->eligible())->toBeTrue()
        ->and((new Rfc8058Eligibility(
            messagePurpose: MessageIntentType::Transactional,
            dkimSignatureValid: true,
            dkimCoveredHeaders: ['List-Unsubscribe', 'List-Unsubscribe-Post'],
        ))->eligible())->toBeFalse()
        ->and((new Rfc8058Eligibility(
            messagePurpose: MessageIntentType::Marketing,
            dkimSignatureValid: true,
            dkimCoveredHeaders: ['List-Unsubscribe'],
        ))->eligible())->toBeFalse();
});

it('accepts the RFC8058 POST idempotently without browser session inputs', function () {
    $token = task0027OneClickToken();
    $handler = new AcceptOneClickUnsubscribe;
    $at = new DateTimeImmutable('2026-09-17T00:01:00+00:00');

    $first = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);
    $replay = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);

    expect($first)->toEqual($replay)
        ->and($first->tokenDigest)->toBe(hash('sha256', $token->value))
        ->and($first->idempotencyKey)->toBe('rfc8058:'.$first->tokenDigest)
        ->and($handler->expectedPostBody())->toBe('List-Unsubscribe=One-Click');
});

it('fails closed on insecure endpoints malformed requests and expired tokens', function () {
    expect(fn () => new Rfc8058Headers('http://example.test/unsubscribe', task0027OneClickToken()))
        ->toThrow(InvalidArgumentException::class, 'HTTPS');

    $handler = new AcceptOneClickUnsubscribe;
    expect(fn () => $handler->handle(
        task0027OneClickToken(),
        'GET',
        'List-Unsubscribe=One-Click',
        new DateTimeImmutable('2026-09-17T00:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'requires POST');

    expect(fn () => $handler->handle(
        task0027OneClickToken(),
        'POST',
        'List-Unsubscribe=One-Click&extra=true',
        new DateTimeImmutable('2026-09-17T00:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must be exactly');

    expect(fn () => $handler->handle(
        task0027OneClickToken(new DateTimeImmutable('2026-09-17T00:00:30+00:00')),
        'POST',
        'List-Unsubscribe=One-Click',
        new DateTimeImmutable('2026-09-17T00:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'expired');
});
