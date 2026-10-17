<?php

namespace App\Modules\Contacts\Presentation\Http\Controllers\Api\V1;

use App\Modules\Core\Presentation\Http\Api\TenantListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GroupingController
{
    public function __construct(private readonly TenantListing $listing) {}

    public function lists(Request $request): JsonResponse
    {
        return $this->listing->respond($request, 'contact_lists', ['id', 'name', 'created_at', 'updated_at']);
    }

    public function tags(Request $request): JsonResponse
    {
        return $this->listing->respond($request, 'tags', ['id', 'name', 'created_at', 'updated_at']);
    }

    public function segments(Request $request): JsonResponse
    {
        return $this->listing->respond($request, 'segment_definitions', ['id', 'name', 'status', 'created_at', 'updated_at']);
    }
}
