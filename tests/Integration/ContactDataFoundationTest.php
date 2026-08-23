<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateCompany;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed contact data tests.');
    }
});

function contactIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Integration '.$suffix,
        'slug' => 'integration-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Workspace '.$suffix,
        'slug' => 'workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Brand '.$suffix,
        'slug' => 'brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'integration-'.$suffix,
        ),
    ];
}

it('persists and audits the canonical contact lifecycle on PostgreSQL', function () {
    $fixture = contactIntegrationTenant('lifecycle');
    $company = app(CreateCompany::class)->handle($fixture['context'], 'Postgres Company', 'Example.COM');
    $contact = app(CreateContact::class)->handle(
        $fixture['context'],
        companyId: $company->id,
        firstName: 'Postgres',
    );
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        'Postgres@Example.COM',
    );

    expect(DB::table('contacts')->where('id', $contact->id)->value('workspace_id'))->toBe($fixture['workspace_id'])
        ->and(DB::table('contact_identities')->where('id', $identity->id)->value('normalized_value'))->toBe('postgres@example.com')
        ->and(DB::table('audit_events')->where('subject_id', $contact->id)->count())->toBeGreaterThanOrEqual(2);
});

it('rejects a contact company reference that crosses PostgreSQL workspace boundaries', function () {
    $primary = contactIntegrationTenant('fk-contact-a');
    $outside = contactIntegrationTenant('fk-contact-b');
    $outsideCompany = app(CreateCompany::class)->handle($outside['context'], 'Outside');
    $now = now();

    expect(fn () => DB::table('contacts')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'brand_id' => null,
        'company_id' => $outsideCompany->id,
        'first_name' => 'Invalid',
        'last_name' => null,
        'display_name' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('rejects a contact identity reference that crosses PostgreSQL workspace boundaries', function () {
    $primary = contactIntegrationTenant('fk-identity-a');
    $outside = contactIntegrationTenant('fk-identity-b');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $now = now();

    expect(fn () => DB::table('contact_identities')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'contact_id' => $outsideContact->id,
        'type' => 'email',
        'value' => 'invalid@example.test',
        'normalized_value' => 'invalid@example.test',
        'provider' => null,
        'provider_reference' => null,
        'verified_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});
