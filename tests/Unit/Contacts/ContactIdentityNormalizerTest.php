<?php

use App\Modules\Contacts\Domain\ContactIdentityNormalizer;
use App\Modules\Contacts\Domain\ContactIdentityType;

it('normalizes email phone and external identities deterministically', function () {
    $normalizer = new ContactIdentityNormalizer;

    $email = $normalizer->normalize(ContactIdentityType::Email, ' Person@Example.COM ');
    $phone = $normalizer->normalize(ContactIdentityType::Phone, ' +1 (212) 555-0100 ');
    $external = $normalizer->normalize(
        ContactIdentityType::External,
        'abc-42',
        'HubSpot',
        'abc-42',
    );

    expect($email->value)->toBe('Person@Example.COM')
        ->and($email->normalizedValue)->toBe('person@example.com')
        ->and($phone->normalizedValue)->toBe('12125550100')
        ->and($external->provider)->toBe('hubspot')
        ->and($external->providerReference)->toBe('abc-42')
        ->and($external->normalizedValue)->toBe('hubspot:abc-42');
});

it('rejects malformed and ambiguous identities', function () {
    $normalizer = new ContactIdentityNormalizer;

    expect(fn () => $normalizer->normalize(ContactIdentityType::Email, 'not-an-email'))
        ->toThrow(InvalidArgumentException::class, 'Contact email identity is invalid.');
    expect(fn () => $normalizer->normalize(ContactIdentityType::Phone, '123'))
        ->toThrow(InvalidArgumentException::class, 'Contact phone identity must contain 7 to 15 digits.');
    expect(fn () => $normalizer->normalize(ContactIdentityType::Email, 'person@example.test', 'provider-only'))
        ->toThrow(InvalidArgumentException::class, 'Provider and provider reference must be supplied together.');
    expect(fn () => $normalizer->normalize(ContactIdentityType::External, 'raw-a', 'hubspot', 'ref-b'))
        ->toThrow(InvalidArgumentException::class, 'External identity value must equal its provider reference.');
});
