<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function deliveryFeatureTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Delivery '.$suffix,
        'slug' => 'delivery-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Workspace '.$suffix,
        'slug' => 'delivery-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Brand '.$suffix,
        'slug' => 'delivery-brand-'.$suffix,
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
            actorId: 'delivery-'.$suffix,
        ),
    ];
}

it('materializes immutable provider-neutral message and recipient snapshots', function () {
    $fixture = deliveryFeatureTenant('materialize');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        'Recipient@Example.COM',
    );

    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        businessIntentKey: 'order-1001-confirmation',
        intentType: MessageIntentType::Transactional,
        channel: DeliveryChannel::Email,
        content: ['subject' => 'Order received', 'text' => 'Thanks for your order.'],
        metadata: ['locale' => 'en'],
    );

    $first = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    expect($first->message->version)->toBe(1)
        ->and($first->message->intentType)->toBe(MessageIntentType::Transactional)
        ->and($first->recipient->destination)->toBe('Recipient@Example.COM')
        ->and($first->recipient->normalizedDestination)->toBe('recipient@example.com')
        ->and(strlen($first->message->contentHash))->toBe(64)
        ->and(strlen($first->recipient->contentHash))->toBe(64);

    DB::table('contact_identities')->where('id', $identity->id)->update([
        'value' => 'new-recipient@example.com',
        'normalized_value' => 'new-recipient@example.com',
        'updated_at' => now(),
    ]);

    $second = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    expect($second->message->version)->toBe(2)
        ->and($second->recipient->destination)->toBe('new-recipient@example.com')
        ->and(DB::table('delivery_recipient_snapshots')->where('id', $first->recipient->id)->value('destination'))
        ->toBe('Recipient@Example.COM')
        ->and(DB::table('audit_events')->whereIn('action', [
            'delivery.message.created',
            'delivery.message_snapshot.materialized',
            'delivery.recipient_snapshot.materialized',
        ])->count())->toBe(5);

    expect(fn () => DB::table('delivery_message_snapshots')->where('id', $first->message->id)->update(['version' => 99]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('delivery_recipient_snapshots')->where('id', $first->recipient->id)->delete())
        ->toThrow(QueryException::class);
});

it('keeps business intent identity workspace scoped and intent type explicit', function () {
    $fixture = deliveryFeatureTenant('intent');

    app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'newsletter-2026-09-07',
        MessageIntentType::Marketing,
        DeliveryChannel::Email,
        ['subject' => 'September update'],
    );

    expect(DB::table('delivery_messages')->value('intent_type'))->toBe('marketing')
        ->and(DB::table('delivery_messages')->value('business_intent_key'))->toBe('newsletter-2026-09-07');

    expect(fn () => app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'newsletter-2026-09-07',
        MessageIntentType::Marketing,
        DeliveryChannel::Email,
        ['subject' => 'Duplicate logical intent'],
    ))->toThrow(\InvalidArgumentException::class);
});

it('fails closed when a recipient identity belongs to another workspace', function () {
    $inside = deliveryFeatureTenant('inside');
    $outside = deliveryFeatureTenant('outside');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $outsideIdentity = app(AddContactIdentity::class)->handle(
        $outside['context'],
        $outsideContact->id,
        ContactIdentityType::Email,
        'outside@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $inside['context'],
        'inside-transaction-1',
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Inside'],
    );

    expect(fn () => app(MaterializeExecutionSnapshots::class)->handle(
        $inside['context'],
        $message->id,
        $outsideContact->id,
        $outsideIdentity->id,
    ))->toThrow(AuthorizationException::class);
});
