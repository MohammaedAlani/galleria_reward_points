<?php

use Illuminate\Support\Facades\Schedule;

// call this export:customers-mail every start day
Schedule::command('export:customers-mail')->dailyAt('00:00');
