<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticationEvidenceEvaluator
{
    /**
     * @param  list<AuthenticationEvidence>  $evidence
     */
    public function evaluate(
        string $workspaceId,
        string $senderDomainId,
        array $evidence,
        DateTimeImmutable $at,
    ): AuthenticationDecision {
        if (trim($workspaceId) === '' || trim($senderDomainId) === '') {
            throw new InvalidArgumentException('Workspace and sender-domain IDs are required for authentication evaluation.');
        }

        /** @var array<string, list<AuthenticationEvidence>> $byDimension */
        $byDimension = [];
        /** @var array<string, AuthenticationEvidenceStatus> $versionStatuses */
        $versionStatuses = [];
        $contradictoryVersions = [];

        foreach ($evidence as $item) {
            if (! $item instanceof AuthenticationEvidence) {
                throw new InvalidArgumentException('Authentication evaluator accepts AuthenticationEvidence values only.');
            }

            if ($item->workspaceId !== $workspaceId || $item->senderDomainId !== $senderDomainId) {
                throw new InvalidArgumentException('Authentication evidence must match the evaluated workspace and sender domain.');
            }

            $byDimension[$item->dimension->value][] = $item;
            $versionKey = $item->dimension->value.'|'.$item->evidenceVersion;

            if (isset($versionStatuses[$versionKey]) && $versionStatuses[$versionKey] !== $item->status) {
                $contradictoryVersions[$versionKey] = true;
            } else {
                $versionStatuses[$versionKey] = $item->status;
            }
        }

        $reasons = [];
        $versions = [];
        $hasUnknown = false;
        $hasStale = false;
        $hasFailure = false;
        $hasContradiction = false;

        foreach (AuthenticationDimension::cases() as $dimension) {
            $candidates = $byDimension[$dimension->value] ?? [];

            if ($candidates === []) {
                $hasUnknown = true;
                $reasons[] = $dimension->value.':missing';

                continue;
            }

            usort($candidates, self::compareEvidence(...));
            $latest = $candidates[array_key_last($candidates)];
            $versions[$dimension->value] = $latest->evidenceVersion;
            $versionKey = $dimension->value.'|'.$latest->evidenceVersion;

            if (isset($contradictoryVersions[$versionKey]) || self::latestObservationContradicts($candidates, $latest)) {
                $hasContradiction = true;
                $reasons[] = $dimension->value.':contradictory';

                continue;
            }

            if ($latest->observedAt > $at) {
                $hasUnknown = true;
                $reasons[] = $dimension->value.':future_observation';

                continue;
            }

            if (! $latest->isFreshAt($at)) {
                $hasStale = true;
                $reasons[] = $dimension->value.($latest->freshUntil === null ? ':freshness_unknown' : ':stale');

                continue;
            }

            match ($latest->status) {
                AuthenticationEvidenceStatus::Pass => null,
                AuthenticationEvidenceStatus::Fail => self::mark($hasFailure, $reasons, $dimension->value.':failed'),
                AuthenticationEvidenceStatus::Unknown => self::mark($hasUnknown, $reasons, $dimension->value.':unknown'),
                AuthenticationEvidenceStatus::Contradictory => self::mark($hasContradiction, $reasons, $dimension->value.':contradictory'),
            };
        }

        $readiness = match (true) {
            $hasContradiction => AuthenticationReadiness::Contradictory,
            $hasFailure => AuthenticationReadiness::Failed,
            $hasStale => AuthenticationReadiness::Stale,
            $hasUnknown => AuthenticationReadiness::Unknown,
            default => AuthenticationReadiness::Ready,
        };

        return new AuthenticationDecision(
            workspaceId: $workspaceId,
            senderDomainId: $senderDomainId,
            readiness: $readiness,
            reasons: $reasons,
            evidenceVersions: $versions,
            decidedAt: $at,
        );
    }

    private static function compareEvidence(AuthenticationEvidence $left, AuthenticationEvidence $right): int
    {
        if ($left->observedAt != $right->observedAt) {
            return $left->observedAt < $right->observedAt ? -1 : 1;
        }

        if ($left->recordedAt != $right->recordedAt) {
            return $left->recordedAt < $right->recordedAt ? -1 : 1;
        }

        return strcmp($left->evidenceVersion, $right->evidenceVersion);
    }

    /**
     * @param  list<AuthenticationEvidence>  $candidates
     */
    private static function latestObservationContradicts(array $candidates, AuthenticationEvidence $latest): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate === $latest || $candidate->observedAt != $latest->observedAt) {
                continue;
            }

            if ($candidate->status !== $latest->status) {
                return true;
            }
        }

        return false;
    }

    private static function mark(bool &$flag, array &$reasons, string $reason): null
    {
        $flag = true;
        $reasons[] = $reason;

        return null;
    }
}
