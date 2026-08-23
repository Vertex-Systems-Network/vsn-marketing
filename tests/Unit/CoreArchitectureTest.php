<?php

it('keeps the Core module layered and provider neutral', function () {
    $root = dirname(__DIR__, 2);
    $module = $root.'/app/Modules/Core';

    foreach (['Domain', 'Application', 'Infrastructure', 'Presentation'] as $layer) {
        expect(is_dir($module.'/'.$layer))->toBeTrue("Missing Core layer: {$layer}");
    }

    $forbidden = ['Brevo\\', 'SendGrid\\', 'Mailgun\\', 'Postmark\\', 'Resend\\', 'Klaviyo\\', 'Mailchimp\\'];
    foreach ([$module.'/Domain', $module.'/Application'] as $boundary) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($boundary));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach ($forbidden as $namespace) {
                expect($source)->not->toContain($namespace);
            }
        }
    }
});
