<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function deliveryOperationFixture(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Operation '.$suffix,
        'slug' => 'operation-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Workspace '.$suffix,
        'slug' => 'operation-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Brand '.$suffix,
        'slug' => 'operation-brand-'.$suffix,
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
            actorId: 'operation-'.$suffix,
        ),
    ];
}

function materializedOperationSnapshots(array $fixture, string $businessIntentKey = 'operation-intent'): array
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        'Recipient@Example.COM',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        $businessIntentKey,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Operation test'],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    return compact('contact', 'identity', 'message', 'snapshots');
}

it('creates one durable logical operation for repeated enqueue requests', function () {
    $fixture = deliveryOperationFixture('idempotent');
    $materialized = materializedOperationSnapshots($fixture, 'invoice-1001');

    $first = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
    );
    $second = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
    );

    expect($second->id)->toBe($first->id)
        ->and($first->state)->toBe(DeliveryOperationState::Ready)
        ->and($first->priorityClass)->toBe(DeliveryPriorityClass::Normal)
        ->and($first->queueName)->toBe('delivery.email.normal')
        ->and(strlen($first->idempotencyKey))->toBe(64)
        ->and(DB::table('delivery_operations')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', EnqueueDeliveryOperation::AUDIT_ACTION)->count())->toBe(1);
});

it('keeps logical idempotency stable when immutable snapshots are rematerialized', function () {
    $fixture = deliveryOperationFixture('rematerialized');
    $materialized = materializedOperationSnapshots($fixture, 'receipt-2002');

    $first = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
    );
    $newSnapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $materialized['message']->id,
        $materialized['contact']->id,
        $materialized['identity']->id,
    );
    $second = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $newSnapshots->message->id,
        $newSnapshots->recipient->id,
    );

    expect($newSnapshots->message->id)->not->toBe($materialized['snapshots']->message->id)
        ->and($newSnapshots->recipient->id)->not->toBe($materialized['snapshots']->recipient->id)
        ->and($second->id)->toBe($first->id)
        ->and($second->idempotencyKey)->toBe($first->idempotencyKey)
        ->and(DB::table('delivery_operations')->count())->toBe(1);
});

it('preserves not-before scheduling in the canonical operation state', function () {
    $fixture = deliveryOperationFixture('scheduled');
    $materialized = materializedOperationSnapshots($fixture, 'scheduled-3003');
    $notBefore = now()->addHour()->toDateTimeImmutable();

    $operation = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
        $notBefore,
        DeliveryPriorityClass::High,
    );

    expect($operation->state)->toBe(DeliveryOperationState::Scheduled)
        ->and($operation->priorityClass)->toBe(DeliveryPriorityClass::High)
        ->and($operation->queueName)->toBe('delivery.email.high')
        ->and($operation->scheduledNotBeforeAt->getTimestamp())->toBe($notBefore->getTimestamp());
});

it('fails closed when snapshot references do not belong to the active workspace', function () {
    $inside = deliveryOperationFixture('inside');
    $outside = deliveryOperationFixture('outside');
    $insideSnapshots = materializedOperationSnapshots($inside, 'inside-4004');
    $outsideSnapshots = materializedOperationSnapshots($outside, 'outside-4004');

    expect(fn () => app(EnqueueDeliveryOperation::class)->handle(
        $inside['context'],
        $insideSnapshots['snapshots']->message->id,
        $outsideSnapshots['snapshots']->recipient->id,
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_operations')->count())->toBe(0);
});

it('fails closed when a brand-scoped context cannot access the message snapshot', function () {
    $fixture = deliveryOperationFixture('brand-scope');
    $materialized = materializedOperationSnapshots($fixture, 'brand-5005');
    $otherBrandId = (string) Str::uuid();

    DB::table('brands')->insert([
        'id' => $otherBrandId,
        'workspace_id' => $fixture['workspace_id'],
        'name' => 'Other Brand',
        'slug' => 'other-brand-operation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherBrandContext = new TenantContext(
        organizationId: $fixture['organization_id'],
        workspaceId: $fixture['workspace_id'],
        brandId: $otherBrandId,
        actorId: 'other-brand-operation',
    );

    expect(fn () => app(EnqueueDeliveryOperation::class)->handle(
        $otherBrandContext,
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_operations')->count())->toBe(0);
});
