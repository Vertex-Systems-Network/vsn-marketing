<?php

namespace App\Modules\Experiments\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CampaignExperimentMatrix
{
    /** @param array<string, array{content:string,time:string,audience:string}> $variants */
    public function __construct(public array $variants)
    {
        if (count($variants) < 1 || count($variants) > 16) {
            throw new InvalidArgumentException('Campaign matrix must have 1 to 16 treatment arms.');
        }
        $dimensions = ['content' => [], 'time' => [], 'audience' => []];
        $tuples = [];
        foreach ($variants as $name => $candidate) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name)
                || array_keys($candidate) !== ['content', 'time', 'audience']
                || ! is_string($candidate['content']) || ! is_string($candidate['audience'])
                || ! is_string($candidate['time']) || ! preg_match('/^[0-9a-f-]{36}$/i', $candidate['content'])
                || ! preg_match('/^[0-9a-f-]{36}$/i', $candidate['audience'])) {
                throw new InvalidArgumentException('Invalid canonical candidate reference.');
            }
            $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $candidate['time'], new DateTimeZone('UTC'));
            if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $candidate['time']) {
                throw new InvalidArgumentException('Candidate timing must be canonical UTC.');
            }
            foreach ($dimensions as $key => $_) {
                $dimensions[$key][$candidate[$key]] = true;
            }
            $tuple = json_encode(array_values($candidate), JSON_THROW_ON_ERROR);
            if (isset($tuples[$tuple])) {
                throw new InvalidArgumentException('Duplicate candidate tuple.');
            }
            $tuples[$tuple] = true;
        }
        if (count($tuples) !== count($dimensions['content']) * count($dimensions['time']) * count($dimensions['audience'])) {
            throw new InvalidArgumentException('Matrix must cover the bounded Cartesian product.');
        }
    }

    public function fingerprint(): string
    {
        $arms = $this->variants;
        ksort($arms, SORT_STRING);

        return hash('sha256', json_encode($arms, JSON_THROW_ON_ERROR));
    }
}
