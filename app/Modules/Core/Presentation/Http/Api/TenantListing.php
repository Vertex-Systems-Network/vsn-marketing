<?php

namespace App\Modules\Core\Presentation\Http\Api;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Cursor-paginated, tenant-scoped read listing shared by simple /api/v1 collections (SRS FR-API-05). */
final class TenantListing
{
    public function __construct(private readonly TenantContextStore $tenant) {}

    /**
     * @param  list<string>  $columns  exposed columns; must include id
     * @param  list<string>  $dates  columns rendered as ISO 8601 UTC
     */
    public function respond(Request $request, string $table, array $columns, array $dates = ['created_at', 'updated_at'], ?string $searchColumn = 'name'): JsonResponse
    {
        $query = $request->validate([
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page.cursor' => ['sometimes', 'string', 'max:200'],
            'filter.search' => ['sometimes', 'string', 'max:120'],
        ]);
        $context = $this->tenant->require();

        $builder = DB::table($table)->where('workspace_id', $context->workspaceId);
        $search = data_get($query, 'filter.search');
        if ($searchColumn !== null && is_string($search) && $search !== '') {
            $builder->where($searchColumn, 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        $page = $builder->orderBy('id')->cursorPaginate(
            (int) data_get($query, 'page.size', 50),
            $columns,
            'page[cursor]',
            data_get($query, 'page.cursor'),
        );

        return new JsonResponse([
            'data' => collect($page->items())->map(function (object $row) use ($columns, $dates): array {
                $out = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    $out[$column] = in_array($column, $dates, true) && $value !== null
                        ? Carbon::parse($value)->toIso8601ZuluString()
                        : $value;
                }

                return $out;
            })->values()->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
