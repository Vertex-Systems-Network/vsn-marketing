<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use InvalidArgumentException;
use LogicException;

final readonly class RedisDeliveryAdmissionCoordinator implements DeliveryAdmissionCoordinator
{
    private const ACQUIRE_SCRIPT = <<<'LUA'
local global_key = KEYS[1]
local workspace_key = KEYS[2]
local member = ARGV[1]
local now_ms = tonumber(ARGV[2])
local expires_ms = tonumber(ARGV[3])
local workspace_limit = tonumber(ARGV[4])
local global_limit = tonumber(ARGV[5])

redis.call('ZREMRANGEBYSCORE', global_key, '-inf', now_ms)
redis.call('ZREMRANGEBYSCORE', workspace_key, '-inf', now_ms)

if redis.call('ZSCORE', global_key, member) then
    return 1
end

if redis.call('ZCARD', global_key) >= global_limit then
    return 0
end

if redis.call('ZCARD', workspace_key) >= workspace_limit then
    return 0
end

redis.call('ZADD', global_key, expires_ms, member)
redis.call('ZADD', workspace_key, expires_ms, member)
return 1
LUA;

    private const RELEASE_SCRIPT = <<<'LUA'
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
return 1
LUA;

    public function __construct(
        private RedisManager $redis,
        private Clock $clock,
    ) {}

    public function tryAcquire(
        string $workspaceId,
        string $operationId,
        int $workspaceConcurrencyLimit,
        int $globalConcurrencyLimit,
        int $ttlSeconds,
    ): bool {
        $this->assertInputs(
            $workspaceId,
            $operationId,
            $workspaceConcurrencyLimit,
            $globalConcurrencyLimit,
            $ttlSeconds,
        );

        $now = $this->clock->now();
        $nowMs = ((int) $now->format('U') * 1000) + intdiv((int) $now->format('u'), 1000);
        $expiresMs = $nowMs + ($ttlSeconds * 1000);
        $member = hash('sha256', $workspaceId."\0".$operationId);

        $result = $this->connection()->eval(
            self::ACQUIRE_SCRIPT,
            2,
            $this->globalKey(),
            $this->workspaceKey($workspaceId),
            $member,
            (string) $nowMs,
            (string) $expiresMs,
            (string) $workspaceConcurrencyLimit,
            (string) $globalConcurrencyLimit,
        );

        return (int) $result === 1;
    }

    public function release(string $workspaceId, string $operationId): void
    {
        if ($workspaceId === '' || $operationId === '') {
            throw new InvalidArgumentException('Workspace ID and operation ID must not be empty.');
        }

        $member = hash('sha256', $workspaceId."\0".$operationId);

        $this->connection()->eval(
            self::RELEASE_SCRIPT,
            2,
            $this->globalKey(),
            $this->workspaceKey($workspaceId),
            $member,
        );
    }

    private function connection(): PhpRedisConnection
    {
        $connection = $this->redis->connection('locks');

        if (! $connection instanceof PhpRedisConnection) {
            throw new LogicException('Redis delivery admission requires the phpredis client.');
        }

        return $connection;
    }

    private function assertInputs(
        string $workspaceId,
        string $operationId,
        int $workspaceConcurrencyLimit,
        int $globalConcurrencyLimit,
        int $ttlSeconds,
    ): void {
        if ($workspaceId === '' || $operationId === '') {
            throw new InvalidArgumentException('Workspace ID and operation ID must not be empty.');
        }

        if ($workspaceConcurrencyLimit < 1 || $globalConcurrencyLimit < 1 || $ttlSeconds < 1) {
            throw new InvalidArgumentException('Concurrency limits and TTL must be positive.');
        }

        if ($workspaceConcurrencyLimit > $globalConcurrencyLimit) {
            throw new InvalidArgumentException('Workspace concurrency limit cannot exceed global concurrency limit.');
        }
    }

    private function globalKey(): string
    {
        return 'delivery:admission:{delivery-admission}:global';
    }

    private function workspaceKey(string $workspaceId): string
    {
        return 'delivery:admission:{delivery-admission}:workspace:'.hash('sha256', $workspaceId);
    }
}
