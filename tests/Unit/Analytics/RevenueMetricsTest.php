<?php

use App\Modules\Analytics\Domain\RevenueDefinition;
use App\Modules\Analytics\Domain\RevenueMetrics;

it('conserves integer credit across varied purchase and partial refund amounts independently of arrival order', function () {
    $start = new DateTimeImmutable('2026-10-02Z');
    $end = new DateTimeImmutable('2026-10-03Z');
    for ($i = 1; $i <= 40; $i++) {
        $amount = $i * 7919;
        $refund = $i * 101;
        $facts = [
            ['id' => 'p', 'subject' => 'one', 'type' => 'order.completed', 'at' => $start->getTimestamp() + 100,
                'money' => ['identity' => 'p1', 'purchase_key' => 'p1', 'amount' => $amount, 'currency' => 'USD']],
            ['id' => 'r', 'subject' => 'one', 'type' => 'order.refunded', 'at' => $start->getTimestamp() + 200,
                'money' => ['identity' => 'r1', 'purchase_key' => 'p1', 'amount' => $refund, 'currency' => 'USD']],
        ];
        $s = new RevenueMetrics;
        $r = $s->calculate(new RevenueDefinition, $facts, $start, $end, $end);
        expect($r['currencies']['USD']['net'])->toBe($amount - $refund)
            ->and(array_sum($r['attribution_credit_minor_units']['USD']))->toBe($amount - $refund)
            ->and($s->calculate(new RevenueDefinition, array_reverse($facts), $start, $end, $end))->toBe($r);
    }
});

it('rejects unsupported models and duplicated canonical inputs without silently truncating', function () {
    expect(fn () => new RevenueDefinition('participation'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RevenueDefinition(lookbackSeconds: 0))->toThrow(InvalidArgumentException::class);
    $f = ['id' => 'duplicate', 'subject' => 'one', 'type' => 'contact.created', 'at' => 1];
    expect(fn () => (new RevenueMetrics)->calculate(new RevenueDefinition, [$f, $f],
        new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), new DateTimeImmutable('2026-10-03Z')))->toThrow(InvalidArgumentException::class);
});
