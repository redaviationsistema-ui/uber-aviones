<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('skygroup:expire-demos')->hourly();
Schedule::command('skygroup:expire-client-access')->hourly();
Schedule::command('skygroup:expire-subscriptions')->hourly();
Schedule::command('skygroup:expire-quotes')->everyFifteenMinutes();
Schedule::command('skygroup:release-provider-payments')->dailyAt('02:00');
