<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// call this export:customers-mail every start day
Artisan::command('export:customers-mail', function () {})->dailyAt('00:00');
