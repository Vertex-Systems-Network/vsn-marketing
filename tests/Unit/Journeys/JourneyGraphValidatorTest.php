<?php

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;

function journeyGraph(): array { return ['schema_version'=>1,'nodes'=>[['id'=>'start','type'=>'trigger','config'=>['event'=>'customer.created']],['id'=>'wait','type'=>'wait','config'=>['seconds'=>60]],['id'=>'end','type'=>'end']], 'edges'=>[['from'=>'start','to'=>'wait'],['from'=>'wait','to'=>'end']]]; }

it('canonicalizes equivalent graphs and produces a stable hash', function () {
    $v = new JourneyGraphValidator(); $a = journeyGraph(); $b = $a; $b['nodes'] = array_reverse($b['nodes']); $b['edges'] = array_reverse($b['edges']);
    expect($v->hash($a))->toBe($v->hash($b));
});

it('rejects unknown nodes, executable text, and foreign edges', function () {
    $v = new JourneyGraphValidator();
    expect(fn () => $v->normalize(['schema_version'=>1,'nodes'=>[['id'=>'x','type'=>'sql','sql'=>'select 1']]]))->toThrow(JourneyDefinitionException::class);
    expect(fn () => $v->normalize(['schema_version'=>1,'nodes'=>[['id'=>'x','type'=>'action','code'=>'exec()']]]))->toThrow(JourneyDefinitionException::class);
    expect(fn () => $v->normalize(['schema_version'=>1,'nodes'=>[['id'=>'x','type'=>'end']],'edges'=>[['from'=>'x','to'=>'foreign']]]))->toThrow(JourneyDefinitionException::class);
});

it('keeps execution and node-attempt identities deterministic and scoped', function () {
    $execution = JourneyExecutionIdentity::for('w1','v1','s1','e1');
    expect($execution)->toBe(JourneyExecutionIdentity::for('w1','v1','s1','e1'))
        ->not->toBe(JourneyExecutionIdentity::for('w2','v1','s1','e1'))
        ->not->toBe(JourneyExecutionIdentity::nodeAttempt($execution,'node',1));
});

it('bounds durable waits and produces UTC deadlines', function () {
    $policy = new JourneyRuntimePolicy(maxWaitSeconds: 3600);
    expect($policy->deadline(new DateTimeImmutable('2026-09-27 00:00:00', new DateTimeZone('UTC')), 60)->format('Y-m-d H:i:s'))->toBe('2026-09-27 00:01:00');
    expect(fn () => $policy->assertWait(3601))->toThrow(JourneyDefinitionException::class);
});
