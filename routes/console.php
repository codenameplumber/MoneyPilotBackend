<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The staleness weighting halves every 21 days, so a median moves even on a day
| when nobody reports anything. Recomputing hourly keeps the figure people
| budget against honest about its own age, and it is cheap: the whole corpus is
| loaded once and every (item x location) pair is computed in memory.
|
| withoutOverlapping matters more than it looks. A refresh that ran longer than
| an hour would otherwise stack, and two of them writing the same aggregate row
| is a race over somebody's food budget.
|
*/

Schedule::command('prices:refresh')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
