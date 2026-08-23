<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command(
    'outbox:dispatch --limit='.(int) config('infrastructure.outbox.batch_size', 100)
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);
