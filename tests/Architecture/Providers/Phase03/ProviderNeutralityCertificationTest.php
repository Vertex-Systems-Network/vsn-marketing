<?php

test('PHASE-03 provider application and domain layers remain connector neutral', function () {
    $roots = [
        base_path('app/Modules/Providers/Application'),
        base_path('app/Modules/Providers/Domain'),
    ];
    $forbidden = [
        'Infrastructure\\Connectors\\',
        'AmazonSes',
        'amazon-ses',
        'Brevo',
        'GmailConnector',
        'gmail-api',
    ];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            expect($source)->not->toBeFalse();
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            foreach ($forbidden as $token) {
                expect(str_contains((string) $source, $token))
                    ->toBeFalse($relative.' must not depend on concrete provider token '.$token);
            }
        }
    }
});
