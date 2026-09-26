<?php

use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentValidator;

function segmentValidator(): SegmentValidator
{
    return new SegmentValidator(new SegmentFieldRegistry);
}

function segmentAttribute(string $field = 'company.domain', string $value = 'example.test'): array
{
    return ['type' => 'attribute', 'field' => $field, 'operator' => 'equals', 'value' => $value];
}

it('normalizes commutative groups and hashes equivalent definitions identically', function () {
    $first = [
        'schema_version' => 1,
        'subject' => 'contact',
        'root' => ['type' => 'group', 'operator' => 'all', 'children' => [
            segmentAttribute('company.domain', 'example.test'),
            segmentAttribute('company.name', 'Example'),
        ]],
    ];
    $second = $first;
    $second['root']['children'] = array_reverse($second['root']['children']);

    $validator = segmentValidator();
    expect($validator->normalize($first))->toBe($validator->normalize($second))
        ->and($validator->hash($first))->toBe($validator->hash($second))
        ->and($validator->serialize($first))->toBe($validator->serialize($second));
});

it('rejects unknown fields operators and arbitrary keys with stable reason codes', function () {
    $validator = segmentValidator();
    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [
            ['type' => 'attribute', 'field' => 'contact.secret', 'operator' => 'equals', 'value' => 'x'],
        ],
    ]];

    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'unknown_or_non_targetable_field');
    $definition['root']['children'][0] = segmentAttribute('company.domain');
    $definition['root']['children'][0]['operator'] = 'regex';
    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'operator_not_allowed_for_field');
    $definition['root']['children'][0] = segmentAttribute();
    $definition['root']['children'][0]['sql'] = 'drop table contacts';
    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'unknown_key');
});

it('rejects empty groups malformed windows and excessive event history', function () {
    $validator = segmentValidator();
    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'any', 'children' => [],
    ]];
    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'invalid_group');

    $definition['root']['children'] = [[
        'type' => 'event', 'name' => 'purchase.completed', 'mode' => 'exists',
        'window' => ['kind' => 'relative', 'days' => 99999],
    ]];
    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'invalid_relative_window');

    $definition['root']['children'][0]['window'] = [
        'kind' => 'absolute', 'from' => '2026-09-26T00:00:00Z', 'to' => '2026-09-26T00:00:00Z',
    ];
    expect(fn () => $validator->normalize($definition))
        ->toThrow(SegmentDefinitionException::class, 'invalid_absolute_window');
});

it('rejects SQL syntax values from becoming structure while preserving them only as data', function () {
    $value = "x' OR 1=1 --";
    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [segmentAttribute('company.domain', $value)],
    ]];
    $normalized = segmentValidator()->normalize($definition);

    expect($normalized['root']['children'][0]['value'])->toBe($value);
});
