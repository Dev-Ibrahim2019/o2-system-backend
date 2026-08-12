<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تقليم سجلات Telescope يومياً — بدونها جدول telescope_entries ينمو بلا حدود.
if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    Schedule::command('telescope:prune --hours=48')->daily();
}
