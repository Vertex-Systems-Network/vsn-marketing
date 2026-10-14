<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Authenticated preview; never changes the application origin or disclosures. */
final readonly class BrandAppearancePreviewController
{
    public function __construct(private TenantContextStore $tenant) {}

    public function __invoke(string $workspace, string $brand): Response
    {
        $context = $this->tenant->require();
        $record = DB::table('brands')->where('id', $brand)->where('workspace_id', $context->workspaceId)
            ->first(['name']);
        if ($record === null) {
            throw new AuthorizationException('Brand preview denied.');
        }

        $appearance = DB::table('brand_appearance_settings')->where('brand_id', $brand)
            ->where('workspace_id', $context->workspaceId)->first(['display_name', 'accent_color', 'logo_asset_id', 'revision']);

        return Inertia::render('enterprise/brand-preview', [
            'display_name' => $appearance->display_name ?? $record->name,
            'accent_color' => $appearance->accent_color ?? '#225588',
            'logo_asset_id' => $appearance->logo_asset_id ?? null,
            'revision' => $appearance->revision ?? 0,
        ]);
    }
}
