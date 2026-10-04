<?php

namespace App\Modules\Experiments\Domain;

use InvalidArgumentException;

final readonly class ExperimentAllocator
{
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Experiment allocation key must be at least 32 bytes.');
        }
    }

    public function keyFingerprint(): string
    {
        return hash_hmac('sha256', 'vsn-experiment-key-fingerprint-v1', $this->key);
    }

    public function subjectKey(ExperimentPlan $plan, string $unitId): string
    {
        if ($unitId === '' || strlen($unitId) > 191) {
            throw new InvalidArgumentException('Invalid randomized unit.');
        }

        return hash_hmac('sha256', json_encode(['workspace' => $plan->workspaceId, 'brand' => $plan->brandId,
            'layer' => $plan->layer, 'kind' => $plan->unitKind, 'unit' => $unitId], JSON_THROW_ON_ERROR), $this->key);
    }

    public function variant(ExperimentPlan $plan, string $unitId): string
    {
        $subject = $this->subjectKey($plan, $unitId);
        $limit = intdiv(4294967296, 10000) * 10000;
        for ($nonce = 0; $nonce < 100; $nonce++) {
            $digest = hash_hmac('sha256', $plan->fingerprint().':'.$subject.':'.$nonce, $this->key);
            $number = hexdec(substr($digest, 0, 8));
            if ($number >= $limit) {
                continue;
            }
            $bucket = $number % 10000;
            foreach ($plan->canonical()['weights'] as $variant => $weight) {
                if ($bucket < $weight) {
                    return $variant;
                }
                $bucket -= $weight;
            }
        }
        throw new InvalidArgumentException('Unable to allocate experiment bucket.');
    }
}
