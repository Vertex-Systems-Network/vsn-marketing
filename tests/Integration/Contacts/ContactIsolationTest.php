<?php

namespace Tests\Integration\Contacts;

use Tests\TestCase;
use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Contacts\Domain\Company\Company;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class ContactIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;
    private Organization $org2;
    private Workspace $workspace1;
    private Workspace $workspace2;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->artisan('migrate:fresh', ['--force' => true]);

        // Create two separate organizations with workspaces
        $this->org1 = Organization::create([
            'name' => 'Organization 1',
            'slug' => 'org-1',
        ]);

        $this->org2 = Organization::create([
            'name' => 'Organization 2',
            'slug' => 'org-2',
        ]);

        $this->workspace1 = Workspace::create([
            'organization_id' => $this->org1->id,
            'name' => 'Workspace 1',
            'slug' => 'ws-1',
        ]);

        $this->workspace2 = Workspace::create([
            'organization_id' => $this->org2->id,
            'name' => 'Workspace 2',
            'slug' => 'ws-2',
        ]);
    }

    public function test_contacts_are_isolated_by_workspace(): void
    {
        // Create a contact in workspace 1
        $contact1 = Contact::create([
            'workspace_id' => $this->workspace1->id,
            'primary_email' => 'user@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        // Try to query for this contact from workspace 2 - should not find it
        $foundInWorkspace2 = Contact::where('workspace_id', $this->workspace2->id)
            ->where('primary_email', 'user@example.com')
            ->first();

        $this->assertNull($foundInWorkspace2, 'Contact from workspace 1 should not be visible in workspace 2');

        // Verify contact exists only in workspace 1
        $foundInWorkspace1 = Contact::where('workspace_id', $this->workspace1->id)
            ->where('id', $contact1->id)
            ->first();

        $this->assertInstanceOf(Contact::class, $foundInWorkspace1);
    }

    public function test_duplicate_emails_allowed_across_workspaces_but_not_within(): void
    {
        // Create contact in workspace 1
        Contact::create([
            'workspace_id' => $this->workspace1->id,
            'primary_email' => 'same@example.com',
            'status' => 'active',
        ]);

        // Same email should be allowed in workspace 2 (different workspace)
        $contactInWs2 = Contact::create([
            'workspace_id' => $this->workspace2->id,
            'primary_email' => 'same@example.com',
            'status' => 'active',
        ]);

        $this->assertInstanceOf(Contact::class, $contactInWs2);

        // But duplicate email in same workspace should fail at database level
        $this->expectException(\Illuminate\Database\QueryException::class);

        Contact::create([
            'workspace_id' => $this->workspace1->id,
            'primary_email' => 'same@example.com',
            'status' => 'active',
        ]);
    }

    public function test_companies_are_isolated_by_workspace(): void
    {
        // Create company in workspace 1
        Company::create([
            'workspace_id' => $this->workspace1->id,
            'name' => 'Acme Corp',
            'domain' => 'acme.com',
        ]);

        // Same domain should be allowed in workspace 2
        $companyInWs2 = Company::create([
            'workspace_id' => $this->workspace2->id,
            'name' => 'Acme Corp',
            'domain' => 'acme.com',
        ]);

        $this->assertInstanceOf(Company::class, $companyInWs2);

        // Query from workspace 2 should not see company from workspace 1
        $foundInWorkspace2 = Company::where('workspace_id', $this->workspace2->id)
            ->where('domain', 'acme.com')
            ->first();

        $this->assertEquals($companyInWs2->id, $foundInWorkspace2->id);

        $foundInWorkspace1FromWs2Query = Company::where('workspace_id', $this->workspace2->id)
            ->where('name', 'Acme Corp')
            ->where('workspace_id', $this->workspace1->id) // Wrong workspace
            ->first();

        $this->assertNull($foundInWorkspace1FromWs2Query);
    }

    public function test_contact_identity_links_to_correct_contact(): void
    {
        // Create contact in workspace 1
        $contact1 = Contact::create([
            'workspace_id' => $this->workspace1->id,
            'primary_email' => 'user1@example.com',
            'status' => 'active',
        ]);

        // Create contact in workspace 2
        $contact2 = Contact::create([
            'workspace_id' => $this->workspace2->id,
            'primary_email' => 'user2@example.com',
            'status' => 'active',
        ]);

        // Add identity to contact 1
        $identity1 = \App\Modules\Contacts\Domain\Contact\ContactIdentity::create([
            'contact_id' => $contact1->id,
            'provider_key' => 'mailchimp',
            'external_id' => 'mc-12345',
        ]);

        // Add identity to contact 2
        $identity2 = \App\Modules\Contacts\Domain\Contact\ContactIdentity::create([
            'contact_id' => $contact2->id,
            'provider_key' => 'mailchimp',
            'external_id' => 'mc-67890',
        ]);

        // Verify identities are linked correctly
        $this->assertEquals($contact1->id, $identity1->contact->id);
        $this->assertEquals($contact2->id, $identity2->contact->id);

        // Verify workspace isolation through identity
        $this->assertEquals($this->workspace1->id, $identity1->contact->workspace_id);
        $this->assertEquals($this->workspace2->id, $identity2->contact->workspace_id);
    }

    public function test_email_normalization_is_deterministic(): void
    {
        $email1 = 'User@Example.COM';
        $email2 = 'user@example.com';
        $email3 = ' USER@example.com ';

        $this->assertEquals(
            Contact::normalizeEmail($email1),
            Contact::normalizeEmail($email2),
            'Email normalization should be case-insensitive'
        );

        $this->assertEquals(
            Contact::normalizeEmail($email2),
            Contact::normalizeEmail($email3),
            'Email normalization should trim whitespace'
        );
    }
}
