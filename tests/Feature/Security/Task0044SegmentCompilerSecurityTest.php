<?php

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\DeterministicSegmentCompiler;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function task0044Workspace(string $label): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $organizationId, 'name' => $label, 'slug' => Str::slug($label),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId, 'organization_id' => $organizationId, 'name' => $label,
        'slug' => Str::slug($label), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $workspaceId;
}

function task0044Context(string $workspaceId): TenantContext
{
    return new TenantContext(Str::uuid()->toString(), $workspaceId, null, Str::uuid()->toString());
}

function task0044Contact(string $workspaceId, ?string $companyId = null): string
{
    $id = (string) Str::uuid();
    DB::table('contacts')->insert([
        'id' => $id, 'workspace_id' => $workspaceId, 'brand_id' => null, 'company_id' => $companyId,
        'first_name' => null, 'last_name' => null, 'display_name' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function task0044Company(string $workspaceId, string $domain): string
{
    $id = (string) Str::uuid();
    DB::table('companies')->insert([
        'id' => $id, 'workspace_id' => $workspaceId, 'brand_id' => null, 'name' => 'Example',
        'domain' => $domain, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function task0044Event(string $workspaceId, string $contactId, string $name, string $occurredAt): void
{
    $typeId = DB::table('event_types')
        ->where('workspace_id', $workspaceId)
        ->where('canonical_name', $name)
        ->value('id');

    if (is_string($typeId) === false) {
        $typeId = (string) Str::uuid();
        DB::table('event_types')->insert([
            'id' => $typeId, 'workspace_id' => $workspaceId, 'canonical_name' => $name,
            'schema_version' => 1, 'created_at' => now(),
        ]);
    }

    DB::table('customer_events')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'brand_id' => null,
        'event_type_id' => $typeId, 'contact_id' => $contactId, 'contact_identity_id' => null,
        'occurred_at' => $occurredAt, 'received_at' => $occurredAt, 'source' => 'test',
        'source_event_id' => null, 'schema_version' => 1, 'subjects' => '[]', 'payload' => '{}',
        'source_metadata' => '{}', 'created_at' => $occurredAt,
    ]);
}

it('binds hostile values and scopes contacts and company joins to the authenticated workspace', function () {
    $workspaceId = task0044Workspace('inside');
    $foreignWorkspaceId = task0044Workspace('outside');
    $domain = "x' OR 1=1 --";
    $insideCompany = task0044Company($workspaceId, $domain);
    $insideContact = task0044Contact($workspaceId, $insideCompany);
    $foreignCompany = task0044Company($foreignWorkspaceId, $domain);
    task0044Contact($foreignWorkspaceId, $foreignCompany);

    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [[
            'type' => 'attribute', 'field' => 'company.domain', 'operator' => 'equals', 'value' => $domain,
        ]],
    ]];
    $compiled = app(DeterministicSegmentCompiler::class)->compile(
        $definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'),
    );
    $ids = $compiled->query->pluck('c.id')->all();

    $otherBrandScope = new TenantContext(
        Str::uuid()->toString(),
        $workspaceId,
        Str::uuid()->toString(),
        Str::uuid()->toString(),
    );
    $otherBrand = app(DeterministicSegmentCompiler::class)->compile(
        $definition,
        $otherBrandScope,
        new DateTimeImmutable('2026-09-26T12:00:00Z'),
    );

    expect($ids)->toBe([$insideContact])
        ->and($compiled->query->getBindings())->toContain($domain)
        ->and($compiled->evaluationFingerprint)->toBe(
            app(DeterministicSegmentCompiler::class)->compile(
                $definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'),
            )->evaluationFingerprint,
        )
        ->and($otherBrand->evaluationFingerprint)->not->toBe($compiled->evaluationFingerprint);
});

it('rejects foreign event references and applies bounded event predicates', function () {
    $workspaceId = task0044Workspace('events');
    $foreignWorkspaceId = task0044Workspace('other-events');
    $contactId = task0044Contact($workspaceId);
    $foreignContactId = task0044Contact($foreignWorkspaceId);
    task0044Event($workspaceId, $contactId, 'purchase.completed', '2026-09-01 00:00:00');
    task0044Event($foreignWorkspaceId, $foreignContactId, 'foreign.event', '2026-09-01 00:00:00');

    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [[
            'type' => 'event', 'name' => 'purchase.completed', 'mode' => 'exists',
            'window' => ['kind' => 'relative', 'days' => 30],
        ]],
    ]];
    $compiled = app(DeterministicSegmentCompiler::class)->compile(
        $definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'),
    );
    expect($compiled->query->pluck('c.id')->all())->toBe([$contactId]);

    $definition['root']['children'][0]['name'] = 'foreign.event';
    expect(fn () => app(DeterministicSegmentCompiler::class)->compile(
        $definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'),
    ))->toThrow(SegmentDefinitionException::class, 'unknown_or_foreign_event');
});

it('uses a pinned instant and half-open UTC event intervals', function () {
    $workspaceId = task0044Workspace('window');
    $contactId = task0044Contact($workspaceId);
    task0044Event($workspaceId, $contactId, 'opened.email', '2026-09-26 12:00:00');
    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [[
            'type' => 'event', 'name' => 'opened.email', 'mode' => 'count', 'minimum' => 1,
            'window' => ['kind' => 'absolute', 'from' => '2026-09-26T11:00:00Z', 'to' => '2026-09-26T12:00:00Z'],
        ]],
    ]];
    $compiler = app(DeterministicSegmentCompiler::class);
    $first = $compiler->compile($definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'));
    $second = $compiler->compile($definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:01Z'));

    expect($first->query->pluck('c.id')->all())->toBe([])
        ->and($first->evaluationFingerprint)->not->toBe($second->evaluationFingerprint);
});

it('limits first and last event selection to the requested window', function () {
    $workspaceId = task0044Workspace('first-last-window');
    $contactId = task0044Contact($workspaceId);
    task0044Event($workspaceId, $contactId, 'order.placed', '2026-09-01 00:00:00');
    task0044Event($workspaceId, $contactId, 'order.placed', '2026-09-09 00:00:00');
    task0044Event($workspaceId, $contactId, 'order.placed', '2026-09-10 00:00:00');
    task0044Event($workspaceId, $contactId, 'order.placed', '2026-09-20 00:00:00');

    foreach (['first', 'last'] as $mode) {
        $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
            'type' => 'group', 'operator' => 'all', 'children' => [[
                'type' => 'event', 'name' => 'order.placed', 'mode' => $mode,
                'window' => ['kind' => 'absolute', 'from' => '2026-09-08T00:00:00Z', 'to' => '2026-09-12T00:00:00Z'],
            ]],
        ]];
        $compiled = app(DeterministicSegmentCompiler::class)->compile(
            $definition, task0044Context($workspaceId), new DateTimeImmutable('2026-09-26T12:00:00Z'),
        );

        expect($compiled->query->pluck('c.id')->all())->toBe([$contactId]);
    }
});

it('rejects excessive compiler cost before constructing an executable query', function () {
    config(['segmentation.max_cost' => 1]);

    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [
            ['type' => 'attribute', 'field' => 'company.domain', 'operator' => 'equals', 'value' => 'example.test'],
        ],
    ]];

    expect(fn () => app(DeterministicSegmentCompiler::class)->compile(
        $definition,
        task0044Context((string) Str::uuid()),
        new DateTimeImmutable('2026-09-26T12:00:00Z'),
    ))->toThrow(SegmentDefinitionException::class, 'cost_limit_exceeded');
});
