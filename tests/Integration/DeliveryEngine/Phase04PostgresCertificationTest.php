<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0024 PostgreSQL certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0024 PostgreSQL certification requires the pgsql driver.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** @return array{organization_id: string, workspace_id: string, brand_id: string, context: TenantContext} */
function phase04PgCertificationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'PHASE-04 PostgreSQL '.$suffix,
        'slug' => 'phase04-pg-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'PHASE-04 PostgreSQL Workspace '.$suffix,
        'slug' => 'phase04-pg-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'PHASE-04 PostgreSQL Brand '.$suffix,
        'slug' => 'phase04-pg-brand-'.$suffix,
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
            actorId: 'phase04-pg-'.$suffix,
        ),
    ];
}

/** @return array{contact: mixed, identity: mixed, message: mixed, snapshots: mixed} */
function phase04PgCertificationSnapshots(array $fixture, string $intentKey): array
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'PHASE-04 PostgreSQL');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        'phase04-pg-'.$intentKey.'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        $intentKey,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'PHASE-04 PostgreSQL certification'],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    return compact('contact', 'identity', 'message', 'snapshots');
}

it('certifies immutable snapshots logical idempotency and rollback recovery on PostgreSQL', function () {
    $fixture = phase04PgCertificationTenant('durable');
    $materialized = phase04PgCertificationSnapshots($fixture, 'phase04-durable-intent');

    $first = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $materialized['snapshots']->message->id,
        $materialized['snapshots']->recipient->id,
    );
    $initialVersion = (int) DB::table('delivery_operations')->where('id', $first->id)->value('version');

    DB::beginTransaction();
    try {
        $locked = DB::table('delivery_operations')->where('id', $first->id)->lockForUpdate()->first();
        expect($locked)->not->toBeNull();
        DB::table('delivery_operations')->where('id', $first->id)->update([
            'version' => $initialVersion + 77,
            'updated_at' => now(),
        ]);
        DB::rollBack();
    } catch (Throwable $error) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $error;
    }

    $rematerialized = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $materialized['message']->id,
        $materialized['contact']->id,
        $materialized['identity']->id,
    );
    $replayed = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $rematerialized->message->id,
        $rematerialized->recipient->id,
    );

    expect($rematerialized->message->id)->not->toBe($materialized['snapshots']->message->id)
        ->and($rematerialized->recipient->id)->not->toBe($materialized['snapshots']->recipient->id)
        ->and($replayed->id)->toBe($first->id)
        ->and($replayed->idempotencyKey)->toBe($first->idempotencyKey)
        ->and((int) DB::table('delivery_operations')->where('id', $first->id)->value('version'))->toBe($initialVersion)
        ->and(DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1);

    expect(fn () => DB::table('delivery_message_snapshots')
        ->where('id', $materialized['snapshots']->message->id)
        ->update(['content_hash' => str_repeat('0', 64)]))
        ->toThrow(QueryException::class);
});

it('certifies cross-workspace snapshot and operation references fail closed on PostgreSQL', function () {
    $inside = phase04PgCertificationTenant('inside');
    $outside = phase04PgCertificationTenant('outside');
    $insideMaterialized = phase04PgCertificationSnapshots($inside, 'phase04-inside-intent');
    $outsideMaterialized = phase04PgCertificationSnapshots($outside, 'phase04-outside-intent');

    expect(fn () => app(EnqueueDeliveryOperation::class)->handle(
        $inside['context'],
        $insideMaterialized['snapshots']->message->id,
        $outsideMaterialized['snapshots']->recipient->id,
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_operations')->count())->toBe(0);

    expect(fn () => DB::table('delivery_recipient_snapshots')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $inside['workspace_id'],
        'message_snapshot_id' => $insideMaterialized['snapshots']->message->id,
        'contact_id' => $outsideMaterialized['contact']->id,
        'contact_identity_id' => $outsideMaterialized['identity']->id,
        'channel' => 'email',
        'destination' => 'cross-workspace@example.test',
        'normalized_destination' => 'cross-workspace@example.test',
        'identity_provider' => null,
        'identity_provider_reference' => null,
        'identity_verified_at' => null,
        'content_hash' => str_repeat('a', 64),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
