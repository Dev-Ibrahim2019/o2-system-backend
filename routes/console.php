<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CRM order-delay alerts — see CrmOrderDelayAlertService. Every minute is
// the finest grain worth polling at: the threshold itself is expressed in
// whole minutes, so anything faster couldn't observe a crossing any sooner.
// withoutOverlapping() guards against a slow run (a very large active-orders
// table) still executing when the next minute's tick fires.
Schedule::command('crm:orders:check-delays')->everyMinute()->withoutOverlapping();
