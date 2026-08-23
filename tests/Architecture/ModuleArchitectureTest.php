<?php

function architectureAppPath(string $path = ''): string
{
    $appPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';

    return $path === '' ? $appPath : $appPath.DIRECTORY_SEPARATOR.$path;
}

function modulePhpFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(architectureAppPath('Modules'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

test('module namespaces follow the filesystem boundary', function () {
    foreach (modulePhpFiles() as $path) {
        $source = file_get_contents($path);
        expect($source)->not->toBeFalse();

        preg_match('/namespace\s+([^;]+);/', (string) $source, $matches);
        expect($matches[1] ?? null)->not->toBeNull();

        $relativeDirectory = dirname(str_replace(architectureAppPath().DIRECTORY_SEPARATOR, '', $path));
        $expected = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativeDirectory);
        expect($matches[1])->toBe($expected);
    }
});

test('domain layers do not depend on presentation or infrastructure namespaces', function () {
    foreach (modulePhpFiles() as $path) {
        if (! str_contains($path, DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $source = (string) file_get_contents($path);
        expect($source)
            ->not->toContain('\\Presentation\\')
            ->not->toContain('\\Infrastructure\\');
    }
});
