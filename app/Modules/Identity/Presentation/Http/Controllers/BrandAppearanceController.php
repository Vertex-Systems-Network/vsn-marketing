<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Governance\ConfigureBrandAppearance;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class BrandAppearanceController
{
    public function __construct(private ConfigureBrandAppearance $configure, private TenantContextStore $tenant) {}

    public function __invoke(Request $request, string $workspace, string $brand): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        if (array_diff(array_keys($request->all()), ['display_name', 'accent_color', 'logo_asset_id']) !== []) {
            throw ValidationException::withMessages(['appearance' => ['Unsupported appearance field.']]);
        }

        $settings = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'accent_color' => ['required', 'string', 'size:7'],
            'logo_asset_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['revision' => $this->configure->apply(
            $actor, $this->tenant->require(), $brand, $settings,
        )]);
    }
}
