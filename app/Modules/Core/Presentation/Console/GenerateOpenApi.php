<?php

namespace App\Modules\Core\Presentation\Console;

use App\Modules\Core\Application\Api\OpenApiV1;
use App\Modules\Core\Application\Api\TypeScriptClientGenerator;
use Illuminate\Console\Command;

final class GenerateOpenApi extends Command
{
    protected $signature = 'vsn:openapi {--check : Fail if the committed spec or client is stale}';

    protected $description = 'Write openapi/v1.json and the generated TypeScript API client';

    public function handle(TypeScriptClientGenerator $client): int
    {
        $document = OpenApiV1::document();
        $files = [
            base_path('openapi/v1.json') => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            base_path('packages/api-client/src/generated.ts') => $client->generate($document),
        ];

        $stale = [];
        foreach ($files as $path => $contents) {
            if ($this->option('check')) {
                if (! is_file($path) || file_get_contents($path) !== $contents) {
                    $stale[] = $path;
                }

                continue;
            }
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $contents);
            $this->line('Wrote '.str_replace(base_path().'/', '', $path));
        }

        if ($stale !== []) {
            $this->error('Stale generated API files; run `php artisan vsn:openapi`: '.implode(', ', array_map(fn ($p) => str_replace(base_path().'/', '', $p), $stale)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
