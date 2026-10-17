<?php

namespace App\Modules\Publishing\Presentation\Http\Controllers\Api\V1;

use App\Modules\Core\Presentation\Http\Api\TenantListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignController
{
    public function __construct(private readonly TenantListing $listing) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listing->respond($request, 'campaigns', ['id', 'name', 'status', 'created_at', 'updated_at']);
    }
}
