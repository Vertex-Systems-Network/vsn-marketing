<?php

namespace App\Modules\Analytics\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/** Deterministic calculator; only the application admission boundary supplies its facts. */
final class BehaviorMetrics
{
    public function calculate(BehaviorDefinition $definition, array $facts, DateTimeImmutable $start,
        DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        if (count($facts) > 1000 || $start >= $end || $end > $cutoff) {
            throw new InvalidArgumentException('Invalid behavior observation set.');
        }
        $seen = [];
        $subjects = [];
        foreach ($facts as $fact) {
            if (isset($seen[$fact['event_id']])) {
                throw new InvalidArgumentException('Duplicate canonical observation.');
            }
            $seen[$fact['event_id']] = true;
            $subjects[$fact['subject_key']][] = $fact;
        }
        ksort($subjects);
        foreach ($subjects as &$rows) {
            usort($rows, fn (array $a, array $b): int => $this->tuple($a) <=> $this->tuple($b));
        }
        unset($rows);

        return match ($definition->kind) {
            'funnel' => $this->funnel($definition, $subjects, $start, $end, $cutoff),
            'retention' => $this->retention($definition, $subjects, $start, $end, $cutoff),
            'lifecycle' => $this->lifecycle($subjects, $start, $end),
            'performance' => $this->performance($definition, $facts, $start, $end),
            default => throw new InvalidArgumentException('Unsupported behavior definition.'),
        };
    }

    private function funnel(BehaviorDefinition $d, array $subjects, DateTimeImmutable $start,
        DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        $counts = array_fill(0, count($d->events), 0);
        $censored = 0;
        foreach ($subjects as $rows) {
            $entry = $this->entry($rows, $d->events[0], $start, $end);
            if ($entry === null) {
                continue;
            }
            $counts[0]++;
            $limit = $entry[0] + $d->conversionSeconds;
            $censored += (int) ($limit > $cutoff->getTimestamp());
            $previous = $entry;
            for ($step = 1; $step < count($d->events); $step++) {
                $next = null;
                foreach ($rows as $row) {
                    $tuple = $this->tuple($row);
                    if ($tuple > $previous && $tuple[0] <= $limit && $row['event_type'] === $d->events[$step]) {
                        $next = $tuple;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $counts[$step]++;
                $previous = $next;
            }
        }
        $steps = [];
        foreach ($counts as $i => $count) {
            $denominator = $i === 0 ? $counts[0] : $counts[$i - 1];
            $steps[] = ['event' => $d->events[$i], 'subjects' => $count, 'denominator' => $denominator,
                'rate' => $denominator === 0 ? null : $count / $denominator];
        }

        return ['steps' => $steps, 'entered_subjects' => $counts[0], 'incomplete_horizon_subjects' => $censored,
            'conversion_rate' => $counts[0] === 0 ? null : end($counts) / $counts[0],
            'horizon_quality' => $censored === 0 ? 'elapsed_windows_observed' : 'partial_observation',
            'conversion_boundary' => 'inclusive_elapsed_seconds', 'tie_quality' => 'deterministic_not_causal'];
    }

    private function retention(BehaviorDefinition $d, array $subjects, DateTimeImmutable $start,
        DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        $cohort = [];
        foreach ($subjects as $key => $rows) {
            $entry = $this->entry($rows, $d->events[0], $start, $end);
            if ($entry !== null) {
                $cohort[$key] = $entry;
            }
        }
        $bins = [];
        for ($bin = 0; $bin < $d->bins; $bin++) {
            $eligible = $retained = 0;
            foreach ($cohort as $key => $entry) {
                $binStart = $entry[0] + $bin * $d->binSeconds;
                $binEnd = $binStart + $d->binSeconds;
                if ($binEnd > $cutoff->getTimestamp()) {
                    continue;
                }
                $eligible++;
                foreach ($subjects[$key] as $row) {
                    $tuple = $this->tuple($row);
                    if ($row['event_type'] === $d->events[1] && $tuple > $entry
                        && $tuple[0] >= $binStart && $tuple[0] < $binEnd) {
                        $retained++;
                        break;
                    }
                }
            }
            $bins[] = ['index' => $bin, 'eligible_subjects' => $eligible, 'censored_subjects' => count($cohort) - $eligible,
                'retained_subjects' => $eligible === 0 ? null : $retained,
                'rate' => $eligible === 0 ? null : $retained / $eligible];
        }

        return ['cohort_size' => count($cohort), 'bins' => $bins, 'cohort_history' => 'selected_period_only',
            'return_policy' => 'distinct_later_event', 'bin_boundary' => 'half_open_elapsed_utc'];
    }

    private function lifecycle(array $subjects, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $previousStart = $start->getTimestamp() - ($end->getTimestamp() - $start->getTimestamp());
        $counts = ['continuing' => 0, 'observed_current_only' => 0, 'dormant' => 0];
        foreach ($subjects as $rows) {
            $previous = $current = false;
            foreach ($rows as $row) {
                $at = $this->tuple($row)[0];
                $previous = $previous || ($at >= $previousStart && $at < $start->getTimestamp());
                $current = $current || ($at >= $start->getTimestamp() && $at < $end->getTimestamp());
            }
            if ($previous || $current) {
                $counts[$previous && $current ? 'continuing' : ($current ? 'observed_current_only' : 'dormant')]++;
            }
        }

        return ['categories' => $counts, 'current_active' => $counts['continuing'] + $counts['observed_current_only'],
            'previous_active' => $counts['continuing'] + $counts['dormant'], 'prior_history_coverage' => 'unknown',
            'current_only_is_proven_new_acquisition' => false];
    }

    private function performance(BehaviorDefinition $d, array $facts, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $groups = [];
        $missing = 0;
        foreach ($facts as $fact) {
            $at = $this->tuple($fact)[0];
            if ($at < $start->getTimestamp() || $at >= $end->getTimestamp()) {
                continue;
            }
            $dimension = $fact['dimension'] ?? null;
            if ($dimension === null) {
                $missing++;

                continue;
            }
            $groups[$dimension][$fact['event_type']]['events'] = ($groups[$dimension][$fact['event_type']]['events'] ?? 0) + 1;
            $groups[$dimension][$fact['event_type']]['subjects'][$fact['subject_key']] = true;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $dimension => $events) {
            ksort($events);
            foreach ($events as $event => $counts) {
                $result[] = ['dimension' => (string) $dimension, 'event_type' => $event, 'events' => $counts['events'],
                    'unique_subjects' => count($counts['subjects'])];
            }
        }

        return ['groups' => $result, 'missing_or_invalid_dimension_events' => $missing,
            'dimension_provenance' => 'canonical_declared_allowlisted_or_scoped_reference',
            'engagement_quality' => 'observed_events_not_verified_human_activity'];
    }

    private function entry(array $rows, string $type, DateTimeImmutable $start, DateTimeImmutable $end): ?array
    {
        foreach ($rows as $row) {
            $tuple = $this->tuple($row);
            if ($row['event_type'] === $type && $tuple[0] >= $start->getTimestamp() && $tuple[0] < $end->getTimestamp()) {
                return $tuple;
            }
        }

        return null;
    }

    private function tuple(array $fact): array
    {
        return [(new DateTimeImmutable($fact['occurred_at']))->getTimestamp(), $fact['event_id']];
    }
}
