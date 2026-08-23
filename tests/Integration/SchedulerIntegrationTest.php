<?php

use Illuminate\Console\Scheduling\Schedule;

it('registers singleton-safe outbox relay and Horizon metric schedules', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->implode("\n");

    expect($commands)->toContain('outbox:relay')
        ->and($commands)->toContain('horizon:snapshot');
});
