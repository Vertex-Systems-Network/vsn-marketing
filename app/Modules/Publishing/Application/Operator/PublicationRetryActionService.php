<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PublicationRetryActionService
{
    public function __construct(private PublicationRetryPreflightService $preflights) {}

    /**
     * Revalidates the confirmed retry selection against current canonical/provider authority.
     *
     * This bounded TASK-0041 action authorizes provider dispatch but deliberately performs
     * no provider API side effect. Provider execution remains owned by the separately
     * governed publication/provider boundary.
     *
     * @param  list<string>  $publicationAttemptIds
     * @return array<string, mixed>
     */
    public function confirm(
        User $actor,
        TenantContext $context,
        array $publicationAttemptIds,
        string $confirmationHash,
        DateTimeImmutable $at,
    ): array {
        CampaignPayloadGuard::assertSha256($confirmationHash, 'publicationRetry.confirmationHash');

        $preflight = $this->preflights->preflight(
            actor: $actor,
            context: $context,
            publicationAttemptIds: $publicationAttemptIds,
            at: $at,
        );

        if (! hash_equals((string) $preflight['confirmation_hash'], $confirmationHash)) {
            throw new InvalidArgumentException(
                'Publication retry confirmation is stale or does not match current authority.',
            );
        }

        if (($preflight['counts']['eligible'] ?? 0) < 1) {
            throw new InvalidArgumentException('Publication retry has no currently authorized eligible work.');
        }

        return [
            'status' => 'authorized',
            'confirmation_hash' => $preflight['confirmation_hash'],
            'counts' => $preflight['counts'],
            'items' => $preflight['eligible'],
            'provider_dispatch_authorized' => true,
            'provider_side_effect_executed' => false,
            'authority_revalidated_at' => $at
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
