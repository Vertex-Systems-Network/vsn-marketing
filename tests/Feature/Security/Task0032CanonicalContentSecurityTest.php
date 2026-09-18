<?php

use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentLifecycle;
use App\Modules\Content\Infrastructure\Persistence\DatabaseContentRepository;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\Template;
use App\Modules\Templates\Domain\TemplateLifecycle;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use App\Modules\Templates\Infrastructure\Persistence\DatabaseTemplateRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function task0032SecurityWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0032 Security '.$suffix,
        'slug' => 'task0032-security-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0032 Security Workspace '.$suffix,
        'slug' => 'task0032-security-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

it('denies foreign-workspace canonical identity reads instead of returning ambiguous absence', function () {
    $firstWorkspace = task0032SecurityWorkspace('scope-a');
    $secondWorkspace = task0032SecurityWorkspace('scope-b');
    $repository = app(DatabaseContentRepository::class);
    $document = new ContentDocument(
        id: (string) Str::uuid(),
        workspaceId: $secondWorkspace,
        name: 'Foreign document',
        lifecycle: ContentLifecycle::Draft,
        createdByActorId: 'security-user',
        auditProvenance: [],
        createdAt: new DateTimeImmutable('2026-09-19T05:00:00+00:00'),
    );
    $repository->createDocument($document);

    expect(fn () => $repository->findDocument($firstWorkspace, $document->id))
        ->toThrow(AuthorizationException::class, 'access denied');
});

it('rejects provider render payloads from canonical template persistence', function () {
    $workspaceId = task0032SecurityWorkspace('provider-payload');
    $repository = app(DatabaseTemplateRepository::class);
    $at = new DateTimeImmutable('2026-09-19T05:10:00+00:00');
    $template = new Template(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Provider-neutral template',
        lifecycle: TemplateLifecycle::Draft,
        createdByActorId: 'security-user',
        createdAt: $at,
    );
    $repository->createTemplate($template);

    $version = new VersionedDefinition(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: DefinitionKind::Template,
        ownerId: $template->id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [],
        idempotencyKey: 'unsafe-provider-payload',
        createdByActorId: 'security-user',
        createdAt: $at,
    );

    expect(fn () => $repository->appendVersion($version, [
        'layout' => ['provider_html' => '<script>arbitrary()</script>'],
    ]))->toThrow(InvalidArgumentException::class, 'Provider/render payload is forbidden');
});

it('rejects stale optimistic-concurrency updates before identity state can be overwritten', function () {
    $workspaceId = task0032SecurityWorkspace('concurrency');
    $repository = app(DatabaseTemplateRepository::class);
    $createdAt = new DateTimeImmutable('2026-09-19T05:20:00+00:00');
    $template = new Template(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Concurrency template',
        lifecycle: TemplateLifecycle::Draft,
        createdByActorId: 'security-user',
        createdAt: $createdAt,
    );
    $repository->createTemplate($template);

    $active = $template->transitionTo(TemplateLifecycle::Active, new DateTimeImmutable('2026-09-19T05:21:00+00:00'));
    $repository->updateTemplate($active, null);

    $stale = $template->transitionTo(TemplateLifecycle::Archived, new DateTimeImmutable('2026-09-19T05:22:00+00:00'));

    expect(fn () => $repository->updateTemplate($stale, null))
        ->toThrow(InvalidArgumentException::class, 'optimistic concurrency conflict');
});
