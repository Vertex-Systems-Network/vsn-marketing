<?php

namespace App\Modules\Consent\Infrastructure\Unsubscribe;

use App\Modules\Consent\Domain\Suppression\SuppressionRecordGuard;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseUnsubscribeTokenRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function persist(string $id, OpaqueUnsubscribeToken $token, array $metadata = []): OpaqueUnsubscribeToken
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new InvalidArgumentException('Unsubscribe token scope ID must be a UUID.');
        }

        SuppressionRecordGuard::assertSafe($metadata, 'metadata');

        return $this->database->connection()->transaction(function () use ($id, $token, $metadata): OpaqueUnsubscribeToken {
            $scope = $token->scope;
            $this->assertContactScope($scope->workspaceId, $scope->contactId);

            $existing = $this->database->connection()->table('unsubscribe_token_scopes')
                ->where('workspace_id', $scope->workspaceId)
                ->where('token_digest', $token->digest)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $this->assertReplay($existing, $id, $token, $metadata);

                return $token;
            }

            $this->denyIfForeignIdExists($scope->workspaceId, $id);

            try {
                $this->database->connection()->table('unsubscribe_token_scopes')->insert([
                    'id' => $id,
                    'workspace_id' => $scope->workspaceId,
                    'contact_id' => $scope->contactId,
                    'channel' => $scope->channel,
                    'purpose' => $scope->purpose,
                    'scope_type' => $scope->scopeType,
                    'scope_key' => $scope->scopeKey,
                    'token_digest' => $token->digest,
                    'issued_at' => $token->issuedAt,
                    'expires_at' => $token->expiresAt,
                    'metadata' => $this->encode($metadata),
                    'created_at' => $token->issuedAt,
                ]);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('unsubscribe_token_scopes')
                    ->where('workspace_id', $scope->workspaceId)
                    ->where('token_digest', $token->digest)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $this->assertReplay($raceWinner, $id, $token, $metadata);

                    return $token;
                }

                throw $exception;
            }

            return $token;
        });
    }

    public function resolve(string $rawToken): ?OpaqueUnsubscribeToken
    {
        self::assertRawToken($rawToken);
        $digest = hash('sha256', $rawToken);
        $rows = $this->database->connection()->table('unsubscribe_token_scopes')
            ->where('token_digest', $digest)
            ->orderBy('workspace_id')
            ->limit(2)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        if ($rows->count() !== 1) {
            throw new InvalidArgumentException('One-click unsubscribe token scope is ambiguous.');
        }

        $row = $rows->first();

        return new OpaqueUnsubscribeToken(
            value: $rawToken,
            scope: new UnsubscribeScope(
                workspaceId: (string) $row->workspace_id,
                contactId: (string) $row->contact_id,
                channel: (string) $row->channel,
                purpose: (string) $row->purpose,
                scopeType: (string) $row->scope_type,
                scopeKey: $row->scope_key === null ? null : (string) $row->scope_key,
            ),
            issuedAt: new DateTimeImmutable((string) $row->issued_at),
            expiresAt: $row->expires_at === null ? null : new DateTimeImmutable((string) $row->expires_at),
        );
    }

    private static function assertRawToken(string $rawToken): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{32,256}$/', $rawToken) !== 1) {
            throw new InvalidArgumentException('One-click unsubscribe token must be opaque base64url-safe material.');
        }
    }

    private function assertContactScope(string $workspaceId, string $contactId): void
    {
        if ($this->database->connection()->table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId)
            ->exists()) {
            return;
        }

        if ($this->database->connection()->table('contacts')->where('id', $contactId)->exists()) {
            throw new AuthorizationException('Contact access denied.');
        }

        throw new InvalidArgumentException('Contact does not exist in this workspace.');
    }

    private function denyIfForeignIdExists(string $workspaceId, string $id): void
    {
        $row = $this->database->connection()->table('unsubscribe_token_scopes')->where('id', $id)->first();
        if (! $row instanceof stdClass) {
            return;
        }

        if ((string) $row->workspace_id !== $workspaceId) {
            throw new AuthorizationException('Unsubscribe token scope access denied.');
        }

        throw new InvalidArgumentException('Unsubscribe token scope ID already exists in this workspace.');
    }

    private function assertReplay(stdClass $stored, string $id, OpaqueUnsubscribeToken $token, array $metadata): void
    {
        $scope = $token->scope;
        if (
            (string) $stored->id !== $id
            || (string) $stored->workspace_id !== $scope->workspaceId
            || (string) $stored->contact_id !== $scope->contactId
            || (string) $stored->channel !== $scope->channel
            || (string) $stored->purpose !== $scope->purpose
            || (string) $stored->scope_type !== $scope->scopeType
            || ($stored->scope_key === null ? null : (string) $stored->scope_key) !== $scope->scopeKey
            || (string) $stored->token_digest !== $token->digest
            || new DateTimeImmutable((string) $stored->issued_at) != $token->issuedAt
            || ($stored->expires_at === null ? null : new DateTimeImmutable((string) $stored->expires_at)) != $token->expiresAt
            || $this->decode((string) $stored->metadata) !== $metadata
        ) {
            throw new InvalidArgumentException('Unsubscribe token replay conflicts with stored scope evidence.');
        }
    }

    /** @throws JsonException */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string|int, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored unsubscribe token metadata must decode to an array.');
        }

        return $decoded;
    }
}
