<?php

namespace App\Modules\Consent\Application\Unsubscribe;

use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use stdClass;

final readonly class ProcessOneClickUnsubscribe
{
    public function __construct(
        private AcceptOneClickUnsubscribe $acceptOneClick,
        private DatabaseSuppressionRepository $suppressions,
        private DatabaseManager $database,
    ) {}

    public function handle(
        string $rawToken,
        string $method,
        string $body,
        DateTimeImmutable $acceptedAt,
    ): OneClickUnsubscribeResult {
        if (preg_match('/^[A-Za-z0-9_-]{32,256}$/', $rawToken) !== 1) {
            throw new InvalidArgumentException('Invalid one-click unsubscribe request.');
        }

        $digest = hash('sha256', $rawToken);

        return $this->database->connection()->transaction(function () use (
            $rawToken,
            $method,
            $body,
            $acceptedAt,
            $digest,
        ): OneClickUnsubscribeResult {
            $rows = $this->database->connection()->table('unsubscribe_token_scopes')
                ->where('token_digest', $digest)
                ->orderBy('workspace_id')
                ->limit(2)
                ->lockForUpdate()
                ->get();

            if ($rows->count() !== 1) {
                throw new InvalidArgumentException('Invalid one-click unsubscribe request.');
            }

            $row = $rows->first();
            $token = new OpaqueUnsubscribeToken(
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

            $accepted = $this->acceptOneClick->handle($token, $method, $body, $acceptedAt);
            $existing = $this->database->connection()->table('suppression_records')
                ->where('workspace_id', $accepted->scope->workspaceId)
                ->where('idempotency_key', $accepted->idempotencyKey)
                ->first();

            if ($existing instanceof stdClass) {
                return new OneClickUnsubscribeResult(
                    suppressionRecordId: (string) $existing->id,
                    tokenDigest: $accepted->tokenDigest,
                    idempotencyKey: $accepted->idempotencyKey,
                    acceptedAt: new DateTimeImmutable((string) $existing->effective_at),
                );
            }

            $stored = $this->suppressions->appendSuppression(new SuppressionRecord(
                id: (string) $row->id,
                workspaceId: $accepted->scope->workspaceId,
                contactId: $accepted->scope->contactId,
                channel: $accepted->scope->channel,
                purpose: $accepted->scope->purpose,
                authorityType: SuppressionAuthorityType::Unsubscribe,
                sourceType: 'rfc8058_one_click',
                sourceVersion: 'RFC8058',
                providerKey: null,
                idempotencyKey: $accepted->idempotencyKey,
                observedAt: $accepted->acceptedAt,
                effectiveAt: $accepted->acceptedAt,
                freshUntil: null,
                immutableEvidence: [
                    'protocol' => 'RFC8058',
                    'token_digest' => $accepted->tokenDigest,
                    'scope_type' => $accepted->scope->scopeType,
                    'scope_key' => $accepted->scope->scopeKey,
                ],
                metadata: ['ingress' => 'public_one_click'],
            ));

            return new OneClickUnsubscribeResult(
                suppressionRecordId: $stored->id,
                tokenDigest: $accepted->tokenDigest,
                idempotencyKey: $accepted->idempotencyKey,
                acceptedAt: $accepted->acceptedAt,
            );
        });
    }
}
