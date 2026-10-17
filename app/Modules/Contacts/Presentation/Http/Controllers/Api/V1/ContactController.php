<?php

namespace App\Modules\Contacts\Presentation\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only contact endpoints; tenant and permission are enforced by route middleware. */
final class ContactController
{
    private const COLUMNS = ['id', 'brand_id', 'company_id', 'first_name', 'last_name', 'display_name', 'created_at', 'updated_at'];

    public function __construct(private readonly TenantContextStore $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page.cursor' => ['sometimes', 'string', 'max:200'],
            'filter.search' => ['sometimes', 'string', 'max:120'],
        ]);
        $context = $this->tenant->require();
        $size = (int) data_get($query, 'page.size', 50);

        $builder = DB::table('contacts')->where('workspace_id', $context->workspaceId);
        if ($context->brandId !== null) {
            $builder->where('brand_id', $context->brandId);
        }
        $search = data_get($query, 'filter.search');
        if (is_string($search) && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $builder->where(fn ($q) => $q->where('display_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }

        $page = $builder->orderBy('id')->cursorPaginate($size, self::COLUMNS, 'page[cursor]', data_get($query, 'page.cursor'));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn ($row): array => self::present($row))->values()->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function show(string $contact): JsonResponse
    {
        $context = $this->tenant->require();
        $row = DB::table('contacts')->where('workspace_id', $context->workspaceId)
            ->when($context->brandId !== null, fn ($q) => $q->where('brand_id', $context->brandId))
            ->where('id', $contact)->first(self::COLUMNS);

        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return new JsonResponse(['data' => self::present($row)]);
    }

    /** @return array<string, string|null> */
    private static function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'brand_id' => $row->brand_id,
            'company_id' => $row->company_id,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'display_name' => $row->display_name,
            'created_at' => $row->created_at === null ? null : Carbon::parse($row->created_at)->toIso8601ZuluString(),
            'updated_at' => $row->updated_at === null ? null : Carbon::parse($row->updated_at)->toIso8601ZuluString(),
        ];
    }
}
