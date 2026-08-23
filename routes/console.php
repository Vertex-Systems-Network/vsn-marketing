<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
