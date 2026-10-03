<?php

namespace App\Modules\Analytics\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/** Deterministic report ledger over every retained scoped observation, before selecting a period. */
final class RevenueMetrics
{
    public function calculate(RevenueDefinition $d, array $facts, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $cutoff): array
    {
        if (count($facts) > 1000 || $start >= $end || $end > $cutoff) {
            throw new InvalidArgumentException('Revenue observation bound exceeded.');
        }
        usort($facts, fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        $purchases = $refunds = $conflicts = $seen = [];
        $quality = ['invalid_money' => 0, 'duplicate_money' => 0, 'conflicting_identities' => 0,
            'unresolved_refunds' => 0, 'rejected_refunds' => 0];
        foreach ($facts as $f) {
            if (isset($seen[$f['id']])) {
                throw new InvalidArgumentException('Duplicate canonical fact supplied.');
            }
            $seen[$f['id']] = true;
            if (! in_array($f['type'], ['order.completed', 'order.refunded'], true)) {
                continue;
            }
            $m = $f['money'] ?? null;
            if (! is_array($m) || ! is_int($m['amount'] ?? null) || $m['amount'] < 1 || $m['amount'] > 1000000000000
                || ! is_string($m['currency'] ?? null) || ! array_key_exists($m['currency'], RevenueDefinition::CURRENCIES)
                || ! is_string($m['identity'] ?? null) || $m['identity'] === ''
                || ! is_string($m['purchase_key'] ?? null) || $m['purchase_key'] === '') {
                $quality['invalid_money']++;

                continue;
            }
            $key = $m['identity'];
            $value = ['purchase_key' => $m['purchase_key'], 'amount' => $m['amount'], 'currency' => $m['currency'],
                'subject' => $f['subject'], 'at' => $f['at']];
            $collection = $f['type'] === 'order.completed' ? $purchases : $refunds;
            if (isset($collection[$key])) {
                if ($collection[$key]['value'] === $value) {
                    $quality['duplicate_money']++;
                } else {
                    $conflicts[$key] = true;
                }

                continue;
            }
            $item = ['value' => $value, 'id' => $f['id'], 'refunds' => [], 'refunded' => 0];
            if ($f['type'] === 'order.completed') {
                $purchases[$key] = $item;
            } else {
                $refunds[$key] = $item;
            }
        }
        $quality['conflicting_identities'] = count($conflicts);
        foreach ($conflicts as $key => $_) {
            unset($purchases[$key], $refunds[$key]);
        }
        foreach ($refunds as $key => $r) {
            $v = $r['value'];
            $purchase = $purchases[$v['purchase_key']] ?? null;
            if ($purchase === null) {
                $quality['unresolved_refunds']++;

                continue;
            }
            $p = $purchase['value'];
            if ($v['currency'] !== $p['currency'] || $v['subject'] !== $p['subject'] || $v['at'] < $p['at']
                || $v['amount'] > $p['amount'] - $purchase['refunded']) {
                $quality['rejected_refunds']++;

                continue;
            }
            $purchases[$v['purchase_key']]['refunded'] += $v['amount'];
            $purchases[$v['purchase_key']]['refunds'][$key] = ['amount' => $v['amount'], 'at' => $v['at']];
        }
        $currencies = $credits = $ledger = $cohort = [];
        $s = $start->getTimestamp();
        $e = $end->getTimestamp();
        $c = $cutoff->getTimestamp();
        foreach ($facts as $f) {
            if ($f['type'] === $d->cohortEvent && $f['at'] >= $s && $f['at'] < $e) {
                $cohort[$f['subject']] ??= $f['at'];
            }
        }
        foreach ($purchases as $key => $p) {
            $v = $p['value'];
            if ($v['at'] < $s || $v['at'] >= $e) {
                continue;
            }
            $currency = $v['currency'];
            $currencies[$currency] ??= ['exponent' => RevenueDefinition::CURRENCIES[$currency], 'gross' => 0, 'refunded' => 0, 'net' => 0, 'purchases' => 0];
            $net = $v['amount'] - $p['refunded'];
            $currencies[$currency]['gross'] += $v['amount'];
            $currencies[$currency]['refunded'] += $p['refunded'];
            $currencies[$currency]['net'] += $net;
            $currencies[$currency]['purchases']++;
            $eligible = array_values(array_filter($facts, fn (array $f): bool => $f['subject'] === $v['subject']
                && in_array($f['type'], RevenueDefinition::TOUCHES, true) && ($f['channel'] ?? null) !== null
                && $f['at'] >= $v['at'] - $d->lookbackSeconds && $f['at'] < $v['at']));
            $touch = $eligible === [] ? null : ($d->model === 'first_touch' ? $eligible[0] : $eligible[count($eligible) - 1]);
            $channel = $touch['channel'] ?? 'unattributed';
            $credits[$currency][$channel] = ($credits[$currency][$channel] ?? 0) + $net;
            $ledger[] = ['transaction_key' => $key, 'purchase_fact' => $p['id'], 'currency' => $currency,
                'gross' => $v['amount'], 'refunded' => $p['refunded'], 'net' => $net,
                'refund_keys' => array_keys($p['refunds']), 'touch_fact' => $touch['id'] ?? null, 'channel' => $channel];
        }
        $mature = array_filter($cohort, fn (int $at): bool => $at + $d->ltvHorizonSeconds <= $c);
        $ltv = [];
        foreach (RevenueDefinition::CURRENCIES as $currency => $exponent) {
            $net = 0;
            foreach ($purchases as $p) {
                $v = $p['value'];
                $at = $mature[$v['subject']] ?? null;
                if ($at === null || $v['currency'] !== $currency || $v['at'] < $at || $v['at'] >= $at + $d->ltvHorizonSeconds) {
                    continue;
                }
                $net += $v['amount'];
                foreach ($p['refunds'] as $refund) {
                    if ($refund['at'] < $at + $d->ltvHorizonSeconds) {
                        $net -= $refund['amount'];
                    }
                }
            }
            // Exact rational average: never round or use a binary float monetary amount.
            $ltv[$currency] = ['exponent' => $exponent, 'net_minor_units' => $net, 'eligible_subjects' => count($mature),
                'mean_minor_units' => $mature === [] ? null : ['numerator' => $net, 'denominator' => count($mature)]];
        }
        ksort($currencies);
        ksort($credits);
        foreach ($credits as &$byChannel) {
            ksort($byChannel);
        }
        unset($byChannel);

        return ['currencies' => $currencies, 'attribution_credit_minor_units' => $credits, 'ledger' => $ledger,
            'quality' => $quality, 'observed_ltv' => $ltv, 'cohort_size' => count($cohort),
            'censored_subjects' => count($cohort) - count($mature), 'cohort_history' => 'first_observed_in_selected_period',
            'lifetime_prediction' => false, 'causal_incrementality' => false];
    }
}
