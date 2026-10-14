<?php

it('requires executable PHP in every registered PHP test source', function (): void {
    $root = dirname(__DIR__);
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $checked = 0;
    foreach ($sources as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        expect(str_starts_with(ltrim($source), '<?php'))
            ->toBeTrue('Test source contains non-PHP output: '.$file->getPathname());
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});
