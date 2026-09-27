<?php

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyGraphValidator;

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
