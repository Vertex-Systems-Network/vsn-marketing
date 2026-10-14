<?php

namespace App\Modules\Identity\Application\Governance;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Governance\WhiteLabelAppearanceAdmission;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Appearance-only persistence; no custom origin or disclosure override. */
final readonly class ConfigureBrandAppearance
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private WhiteLabelAppearanceAdmission $admission,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $settings */
    public function apply(User $actor, TenantContext $context, string $brandId, array $settings, ?int $expectedRevision = null): int
    {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::BRAND_MANAGE)
            || ! $this->admission->allows($settings)
            || ! isset($settings['display_name'], $settings['accent_color'])
            || ! DB::table('brands')->where('id', $brandId)->where('workspace_id', $context->workspaceId)->exists()) {
            throw new AuthorizationException('Brand appearance change denied.');
        }

        return DB::transaction(function () use ($actor, $context, $brandId, $settings, $expectedRevision): int {
            // Serialize initial creation as well as edits of the existing settings.
            DB::table('brands')->where('id', $brandId)->where('workspace_id', $context->workspaceId)
                ->lockForUpdate()->firstOrFail();
            $logo = $settings['logo_asset_id'] ?? null;
            if ($logo !== null && ! DB::table('assets')->where('id', $logo)
                ->where('workspace_id', $context->workspaceId)->where('kind', 'image')
                ->where('lifecycle', 'active')->lockForUpdate()->first(['id'])) {
                throw new AuthorizationException('Brand logo asset denied.');
            }
            $existing = DB::table('brand_appearance_settings')->where('brand_id', $brandId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first(['revision', 'created_at']);
            if ($expectedRevision !== null && $expectedRevision !== (int) ($existing->revision ?? 0)) {
                throw new ConflictHttpException('Appearance changed. Reload before saving.');
            }
            $revision = $existing === null ? 1 : (int) $existing->revision + 1;
            DB::table('brand_appearance_settings')->updateOrInsert(
                ['brand_id' => $brandId, 'workspace_id' => $context->workspaceId],
                ['display_name' => $settings['display_name'], 'accent_color' => strtoupper($settings['accent_color']),
                    'logo_asset_id' => $logo, 'revision' => $revision,
                    'created_at' => $existing->created_at ?? now(), 'updated_at' => now()],
            );
            $this->audit->record($context->workspaceId, 'identity.brand.appearance_configured', [
                'brand_id' => $brandId, 'revision' => $revision,
            ], brandId: $brandId, actorId: (string) $actor->getKey());

            return $revision;
        });
    }
}
