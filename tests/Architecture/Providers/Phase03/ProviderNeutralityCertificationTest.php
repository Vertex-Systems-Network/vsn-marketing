<?php

test('PHASE-03 provider application and domain layers remain connector neutral', function () {
    $providerPath = dirname(__DIR__, 4)
        .DIRECTORY_SEPARATOR.'app'
        .DIRECTORY_SEPARATOR.'Modules'
        .DIRECTORY_SEPARATOR.'Providers';

    $roots = [
        $providerPath.DIRECTORY_SEPARATOR.'Application',
        $providerPath.DIRECTORY_SEPARATOR.'Domain',
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

            foreach ($forbidden as $token) {
                expect(str_contains((string) $source, $token))->toBeFalse();
            }
        }
    }
});
