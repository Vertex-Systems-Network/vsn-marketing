<?php

namespace Tests\Support;

use App\Modules\Consent\Application\RecordConsent;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Events\Application\PersistCustomerEvent;
use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class AnalyticsFixture implements Clock
{
    public DateTimeImmutable $time;

    public TenantContext $actor;

    public string $contact;

    public string $role;

    public function __construct(bool $consented = true)
    {
        $this->time = new DateTimeImmutable('2026-10-03T12:00:00Z');
        app()->instance(Clock::class, $this);
        config(['analytics.purpose_approved' => true, 'analytics.retention_days' => 30]);
        $suffix = strtolower(Str::random(12));
        $org = Organization::query()->create(['name' => 'Analytics', 'slug' => 'analytics-'.$suffix]);
        $workspace = Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => 'Analytics', 'slug' => 'analytics-'.$suffix]);
        $brand = Brand::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Analytics', 'slug' => 'analytics-'.$suffix]);
        $user = User::query()->create(['name' => 'Analyst', 'email' => $suffix.'@example.test', 'password' => 'test-only']);
        $this->actor = new TenantContext((string) $org->getKey(), (string) $workspace->getKey(), (string) $brand->getKey(), (string) $user->getKey());
        $roles = app(WorkspaceRoleManager::class);
        $member = $roles->addMember($user, $this->actor->workspaceId);
        $this->role = $roles->createRole($this->actor->workspaceId, 'analyst', 'Analyst');
        $roles->grantPermission($this->role, PermissionCatalog::ANALYTICS_READ);
        $roles->grantPermission($this->role, PermissionCatalog::CONTACT_WRITE);
        $roles->assignRole($member, $this->role);
        $this->contact = app(CreateContact::class)->handle($this->actor, firstName: 'Private name')->id;
        if ($consented) {
            $this->consent(ConsentDecision::Granted, '2026-10-01T00:00:00Z');
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function consent(ConsentDecision $decision, string $at): void
    {
        app(RecordConsent::class)->handle($this->actor, $this->contact, 'analytics', 'measurement', 'test', $decision, new DateTimeImmutable($at));
    }

    public function event(?string $sourceId = null, string $occurred = '2026-10-02T10:00:00Z', string $received = '2026-10-02T10:01:00Z'): string
    {
        $id = (string) Str::uuid();
        app(PersistCustomerEvent::class)->handle($this->actor, new CanonicalEvent(
            $id, 'product.viewed', new DateTimeImmutable($occurred), new DateTimeImmutable($received),
            $this->actor->workspaceId, $this->actor->brandId, ['contact_id' => $this->contact], 'fixture', $sourceId,
            1, ['private_name' => 'Private name'], ['provider_event_name' => 'view'],
        ));

        return $id;
    }
}
