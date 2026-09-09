<?php

function deliveryEngineSources(): string
{
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR.'DeliveryEngine';
    $source = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $source .= "\n".file_get_contents($file->getPathname());
        }
    }

    return $source;
}

test('DeliveryEngine remains provider neutral and keeps framework queue policy outside the module', function () {
    $source = deliveryEngineSources();

    expect($source)
        ->not->toContain('Amazon SES')
        ->not->toContain('Brevo')
        ->not->toContain('Gmail')
        ->not->toContain('Aws\\')
        ->not->toContain('Google\\')
        ->not->toContain('ShouldQueue')
        ->not->toContain('RateLimiter');
});
