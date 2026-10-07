<?php

it('keeps every committed PHP test executable instead of shell or tool output', function (): void {
    $root = base_path('tests');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toBeFalse();

        $normalized = ltrim((string) $contents, "\xEF\xBB\xBF \t\r\n");
        expect(
            str_starts_with($normalized, '<?php'),
            $file->getPathname().' must start with a PHP opening tag and must not contain captured shell/tool output.',
        )->toBeTrue();

        expect($normalized)->not->toContain('/workspace/scratch/')
            ->not->toContain("sed: can't read")
            ->not->toContain('No such file or directory');
    }
});
