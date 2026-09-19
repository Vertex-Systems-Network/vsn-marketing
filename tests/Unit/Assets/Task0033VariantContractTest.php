<?php

use App\Modules\Assets\Application\Transformation\VariantPlan;
use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use App\Modules\Assets\Domain\Variant\TransformationOperation;
use App\Modules\Assets\Domain\Variant\TransformationSpec;
use App\Modules\Assets\Domain\Variant\TransformationStep;

function task0033VariantSpec(array $resize = ['width' => 1200, 'height' => 630, 'fit' => 'cover']): TransformationSpec
{
    return new TransformationSpec([
        new TransformationStep(TransformationOperation::Resize, $resize),
        new TransformationStep(TransformationOperation::Convert, ['media_type' => 'image/webp']),
        new TransformationStep(TransformationOperation::Quality, ['quality' => 82]),
    ]);
}

it('canonicalizes equivalent transformation parameters to the same deterministic hash', function () {
    $first = task0033VariantSpec([
        'width' => 1200,
        'height' => 630,
        'fit' => 'cover',
    ]);
    $second = task0033VariantSpec([
        'fit' => 'cover',
        'height' => 630,
        'width' => 1200,
    ]);

    expect($first->hash())->toBe($second->hash())
        ->and($first->canonicalJson())->toBe($second->canonicalJson())
        ->and($first->hash())->toMatch('/^[a-f0-9]{64}$/');
});

it('preserves ordered transform semantics while keeping exact source and processor identity pinned', function () {
    $processor = new ProcessorIdentity('image-pipeline', '2026.09.1');

    $first = new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'original-1',
        transformation: task0033VariantSpec(),
        processor: $processor,
        idempotencyKey: 'variant-request-1',
    );

    $retry = new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'original-1',
        transformation: task0033VariantSpec([
            'fit' => 'cover',
            'height' => 630,
            'width' => 1200,
        ]),
        processor: new ProcessorIdentity('image-pipeline', '2026.09.1'),
        idempotencyKey: 'another-retry-token',
    );

    expect($first->isReplayEquivalentTo($retry))->toBeTrue()
        ->and($first->deterministicRequest()['source_original_id'])->toBe('original-1')
        ->and($first->deterministicRequest()['processor_version'])->toBe('2026.09.1');

    $differentSource = new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'original-2',
        transformation: task0033VariantSpec(),
        processor: $processor,
        idempotencyKey: 'variant-request-2',
    );

    $differentProcessor = new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'original-1',
        transformation: task0033VariantSpec(),
        processor: new ProcessorIdentity('image-pipeline', '2026.10.0'),
        idempotencyKey: 'variant-request-3',
    );

    expect($first->isReplayEquivalentTo($differentSource))->toBeFalse()
        ->and($first->isReplayEquivalentTo($differentProcessor))->toBeFalse();
});

it('treats transformation step order as semantically significant', function () {
    $resize = new TransformationStep(TransformationOperation::Resize, ['width' => 800]);
    $convert = new TransformationStep(TransformationOperation::Convert, ['media_type' => 'image/webp']);

    $resizeThenConvert = new TransformationSpec([$resize, $convert]);
    $convertThenResize = new TransformationSpec([$convert, $resize]);

    expect($resizeThenConvert->hash())->not->toBe($convertThenResize->hash());
});

it('rejects unbounded unknown or ambient-resource transformation inputs', function () {
    expect(fn () => new TransformationStep(
        TransformationOperation::Resize,
        ['width' => 0],
    ))->toThrow(InvalidArgumentException::class, 'between 1 and 10000');

    expect(fn () => new TransformationStep(
        TransformationOperation::Resize,
        ['width' => 1200, 'url' => 'https://example.test/image.png'],
    ))->toThrow(InvalidArgumentException::class, 'Unsupported resize transformation parameter');

    expect(fn () => new TransformationStep(
        TransformationOperation::Convert,
        ['media_type' => 'IMAGE/PNG'],
    ))->toThrow(InvalidArgumentException::class, 'normalized lowercase');

    expect(fn () => new TransformationStep(
        TransformationOperation::Crop,
        ['x' => 0, 'y' => 0, 'width' => 100],
    ))->toThrow(InvalidArgumentException::class, 'requires height');

    expect(fn () => new TransformationStep(
        TransformationOperation::Quality,
        ['quality' => 101],
    ))->toThrow(InvalidArgumentException::class, 'between 1 and 100');
});

it('bounds transformation complexity and processor identity without executing processors', function () {
    $steps = array_fill(
        0,
        17,
        new TransformationStep(TransformationOperation::Quality, ['quality' => 80]),
    );

    expect(fn () => new TransformationSpec($steps))
        ->toThrow(InvalidArgumentException::class, 'maximum step count');

    expect(fn () => new ProcessorIdentity('image pipeline;rm', '1.0.0'))
        ->toThrow(InvalidArgumentException::class, 'unsupported characters');

    expect(fn () => new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'original-1',
        transformation: task0033VariantSpec(),
        processor: new ProcessorIdentity('image-pipeline', '1.0.0'),
        idempotencyKey: str_repeat('x', 192),
    ))->toThrow(InvalidArgumentException::class, 'must not exceed 191 characters');
});
