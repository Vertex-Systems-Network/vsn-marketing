<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Tag\Tag;
use Illuminate\Support\Facades\DB;

class CreateTag
{
    public function execute(int $workspaceId, string $name, ?string $color = null, array $metadata = []): Tag
    {
        return DB::transaction(function () use ($workspaceId, $name, $color, $metadata) {
            return Tag::create([
                'workspace_id' => $workspaceId,
                'name' => $name,
                'color' => $color,
                'metadata' => $metadata,
            ]);
        });
    }
}
