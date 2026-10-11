<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\BoundedAutonomyOperationInventoryDigest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOperationInventoryDigestTest extends TestCase
{
    private function facts(): array
    {
        return [
            'tenant' => ['organization_id' => 'org', 'workspace_id' => 'workspace', 'brand_id' => 'brand', 'actor_id' => 'operator'],
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'observed_at_unix' => 1760000000,
            'expires_at_unix' => 1760000300,
            'complete' => false,
            'operations' => [['operation_id' => 'send-1', 'idempotency_key' => 'key-1']],
            'operation_set_sha256' => str_repeat('b', 64),
        ];
    }

    public function test_manifest_binds_completeness_clock_tenant_and_operation_list(): void
    {
        $base = $this->facts();
        $original = BoundedAutonomyOperationInventoryDigest::sha256($base);
        foreach ([
            ['complete' => true],
            ['observed_at_unix' => 1759999999],
            ['expires_at_unix' => 1760000400],
            ['run_id' => 'run-2'],
            ['tenant' => ['organization_id' => 'other', 'workspace_id' => 'workspace', 'brand_id' => 'brand', 'actor_id' => 'operator']],
            ['operations' => []],
            ['operation_set_sha256' => str_repeat('c', 64)],
        ] as $change) {
            self::assertNotSame($original, BoundedAutonomyOperationInventoryDigest::sha256(
                array_replace($base, $change),
            ));
        }
    }

    public function test_missing_completeness_field_cannot_be_hashed(): void
    {
        $facts = $this->facts();
        unset($facts['complete']);
        $this->expectException(InvalidArgumentException::class);
        BoundedAutonomyOperationInventoryDigest::sha256($facts);
    }
}
