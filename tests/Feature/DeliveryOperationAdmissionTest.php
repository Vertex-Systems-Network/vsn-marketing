<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\ScheduleDeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Application\CreateProviderConnection;
use App\Modules\Providers\Application\RegisterProvider;
use App\Modules\Providers\Domain\AuthFamily;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function deliveryOperationTenant(string $suffix): array
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
        'name' => 'Operation Workspace '.$suffix,
        'slug' => 'operation-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Operation Brand '.$suffix,
        'slug' => 'operation-brand-'.$suffix,
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
            actorId: 'operation-'.$suffix,
        ),
    ];
}

function deliveryOperationFixture(string $suffix, MessageIntentType $intent = MessageIntentType::Transactional): array
{
    $tenant = deliveryOperationTenant($suffix);
    $contact = app(CreateContact::class)->handle($tenant['context'], firstName: 'Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $tenant['context'],
        $contact->id,
        ContactIdentityType::Email,
        $suffix.'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $tenant['context'],
        businessIntentKey: 'intent-'.$suffix,
        intentType: $intent,
        channel: DeliveryChannel::Email,
        content: ['subject' => 'Delivery '.$suffix, 'text' => 'Body '.$suffix],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $tenant['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );
    $provider = app(RegisterProvider::class)->handle(
        $tenant['context'],
        'provider-'.$suffix,
        'Provider '.$suffix,
        'https://example.test/provider/'.$suffix,
    );
    $providerConnection = app(CreateProviderConnection::class)->handle(
        $tenant['context'],
        $provider->id,
        'Primary',
        AuthFamily::ApiKey,
        'env://DELIVERY_OPERATION_'.strtoupper($suffix).'_KEY',
        'https://example.test/provider/'.$suffix,
        readiness: ProviderReadinessStatus::Ready,
    );

    return $tenant + [
        'contact' => $contact,
        'identity' => $identity,
        'message' => $message,
        'snapshots' => $snapshots,
        'provider' => $provider,
        'provider_connection' => $providerConnection,
    ];
}

it('creates one durable logical operation and reuses it on an exact retry', function () {
    $fixture = deliveryOperationFixture('retry');
    $notBefore = new DateTimeImmutable('2026-09-01T00:00:00+00:00');

    $first = app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $fixture['snapshots']->message->id,
        $fixture['snapshots']->recipient->id,
        $fixture['provider_connection']->id,
        $notBefore,
    );
    $second = app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $fixture['snapshots']->message->id,
        $fixture['snapshots']->recipient->id,
        $fixture['provider_connection']->id,
        $notBefore,
    );

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->operation->id)->toBe($first->operation->id)
        ->and($first->operation->state)->toBe(DeliveryOperationState::Ready)
        ->and($first->operation->priorityClass)->toBe(DeliveryPriorityClass::Transactional)
        ->and(strlen($first->operation->idempotencyKey))->toBe(64)
        ->and(DB::table('delivery_operations')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', ScheduleDeliveryOperation::CREATED_AUDIT_ACTION)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', ScheduleDeliveryOperation::REUSED_AUDIT_ACTION)->count())->toBe(1);
});

it('reuses equivalent rematerialized snapshots but rejects changed content for the same logical intent', function () {
    $fixture = deliveryOperationFixture('rematerialized');
    $notBefore = new DateTimeImmutable('2026-09-01T00:00:00+00:00');

    $first = app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $fixture['snapshots']->message->id,
        $fixture['snapshots']->recipient->id,
        $fixture['provider_connection']->id,
        $notBefore,
    );
    $equivalent = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $fixture['message']->id,
        $fixture['contact']->id,
        $fixture['identity']->id,
    );
    $reused = app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $equivalent->message->id,
        $equivalent->recipient->id,
        $fixture['provider_connection']->id,
        $notBefore,
    );

    expect($reused->created)->toBeFalse()
        ->and($reused->operation->id)->toBe($first->operation->id)
        ->and(DB::table('delivery_operations')->count())->toBe(1);

    DB::table('delivery_messages')->where('id', $fixture['message']->id)->update([
        'content' => json_encode(['subject' => 'Changed subject', 'text' => 'Changed body'], JSON_THROW_ON_ERROR),
    ]);
    $changed = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $fixture['message']->id,
        $fixture['contact']->id,
        $fixture['identity']->id,
    );

    expect(fn () => app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $changed->message->id,
        $changed->recipient->id,
        $fixture['provider_connection']->id,
        $notBefore,
    ))->toThrow(LogicException::class, 'changed execution snapshot content');
});

it('keeps future marketing operations scheduled with an explicit provider-neutral priority class', function () {
    $fixture = deliveryOperationFixture('future', MessageIntentType::Marketing);

    $result = app(ScheduleDeliveryOperation::class)->handle(
        $fixture['context'],
        $fixture['snapshots']->message->id,
        $fixture['snapshots']->recipient->id,
        $fixture['provider_connection']->id,
        new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
    );

    expect($result->operation->state)->toBe(DeliveryOperationState::Scheduled)
        ->and($result->operation->priorityClass)->toBe(DeliveryPriorityClass::Marketing)
        ->and(DB::table('delivery_operations')->value('priority_class'))->toBe('marketing');
});

it('fails closed on cross-workspace provider connections and mismatched execution snapshots', function () {
    $inside = deliveryOperationFixture('inside');
    $outside = deliveryOperationFixture('outside');
    $notBefore = new DateTimeImmutable('2026-09-01T00:00:00+00:00');

    expect(fn () => app(ScheduleDeliveryOperation::class)->handle(
        $inside['context'],
        $inside['snapshots']->message->id,
        $inside['snapshots']->recipient->id,
        $outside['provider_connection']->id,
        $notBefore,
    ))->toThrow(AuthorizationException::class, 'Delivery operation admission denied.');

    $otherMessage = app(CreateDeliveryMessage::class)->handle(
        $inside['context'],
        businessIntentKey: 'intent-inside-other',
        intentType: MessageIntentType::Transactional,
        channel: DeliveryChannel::Email,
        content: ['subject' => 'Other'],
    );
    $otherSnapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $inside['context'],
        $otherMessage->id,
        $inside['contact']->id,
        $inside['identity']->id,
    );

    expect(fn () => app(ScheduleDeliveryOperation::class)->handle(
        $inside['context'],
        $inside['snapshots']->message->id,
        $otherSnapshots->recipient->id,
        $inside['provider_connection']->id,
        $notBefore,
    ))->toThrow(LogicException::class, 'Recipient execution snapshot does not belong');
});
