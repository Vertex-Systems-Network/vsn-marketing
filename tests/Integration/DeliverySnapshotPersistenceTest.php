<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed delivery snapshot tests.');
    }
});

function deliveryIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Delivery Integration '.$suffix,
        'slug' => 'delivery-integration-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Workspace '.$suffix,
        'slug' => 'delivery-integration-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Brand '.$suffix,
        'slug' => 'delivery-integration-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'delivery-integration-'.$suffix,
        ),
    ];
}

it('persists immutable execution snapshots and provenance on PostgreSQL', function () {
    $fixture = deliveryIntegrationTenant('immutable');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Postgres');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        'postgres-recipient@example.test',
        provider: 'source-system',
        providerReference: 'identity-42',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'invoice-42-issued',
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Invoice 42', 'text' => 'Your invoice is ready.'],
        ['source' => 'billing'],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    expect(DB::table('delivery_message_snapshots')->where('id', $snapshots->message->id)->value('content_hash'))
        ->toBe($snapshots->message->contentHash)
        ->and(DB::table('delivery_recipient_snapshots')->where('id', $snapshots->recipient->id)->value('identity_provider'))
        ->toBe('source-system')
        ->and(DB::table('delivery_recipient_snapshots')->where('id', $snapshots->recipient->id)->value('identity_provider_reference'))
        ->toBe('identity-42');

    expect(fn () => DB::table('delivery_message_snapshots')->where('id', $snapshots->message->id)->update(['content_hash' => str_repeat('0', 64)]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('delivery_recipient_snapshots')->where('id', $snapshots->recipient->id)->delete())
        ->toThrow(QueryException::class);
});

it('rejects cross-workspace snapshot references at the PostgreSQL database boundary', function () {
    $primary = deliveryIntegrationTenant('fk-primary');
    $outside = deliveryIntegrationTenant('fk-outside');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $outsideIdentity = app(AddContactIdentity::class)->handle(
        $outside['context'],
        $outsideContact->id,
        ContactIdentityType::Email,
        'outside-fk@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $primary['context'],
        'primary-fk-intent',
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Primary'],
    );
    $insideContact = app(CreateContact::class)->handle($primary['context'], firstName: 'Inside');
    $insideIdentity = app(AddContactIdentity::class)->handle(
        $primary['context'],
        $insideContact->id,
        ContactIdentityType::Email,
        'inside-fk@example.test',
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $primary['context'],
        $message->id,
        $insideContact->id,
        $insideIdentity->id,
    );

    expect(fn () => DB::table('delivery_recipient_snapshots')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'message_snapshot_id' => $snapshots->message->id,
        'contact_id' => $outsideContact->id,
        'contact_identity_id' => $outsideIdentity->id,
        'channel' => 'email',
        'destination' => 'outside-fk@example.test',
        'normalized_destination' => 'outside-fk@example.test',
        'identity_provider' => null,
        'identity_provider_reference' => null,
        'identity_verified_at' => null,
        'content_hash' => str_repeat('a', 64),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
