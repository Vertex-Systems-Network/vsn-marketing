<?php

use App\Modules\Consent\Domain\ConsentValueNormalizer;

it('normalizes canonical consent dimensions deterministically', function () {
    $normalizer = new ConsentValueNormalizer;

    expect($normalizer->channel(' EMAIL '))->toBe('email')
        ->and($normalizer->purpose(' Product Updates '))->toBe('product updates')
        ->and($normalizer->source(' Signup-Form '))->toBe('signup-form');
});

it('rejects empty consent dimensions', function () {
    $normalizer = new ConsentValueNormalizer;

    expect(fn () => $normalizer->channel('   '))
        ->toThrow(InvalidArgumentException::class, 'Consent channel is required.');
    expect(fn () => $normalizer->purpose('   '))
        ->toThrow(InvalidArgumentException::class, 'Consent purpose is required.');
    expect(fn () => $normalizer->source('   '))
        ->toThrow(InvalidArgumentException::class, 'Consent source is required.');
});
