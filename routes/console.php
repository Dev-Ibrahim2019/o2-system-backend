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

// Call-center order slots: safety net for status changes that bypass model events, and the trigger
// that hands a freed slot to a queued order once its reuse cooldown has passed (see OrderSlotService).
Schedule::command('call-center:slots:reconcile')->everyMinute()->withoutOverlapping();

// Closed call-center orders → takeaway system, from the outbox with retry/backoff (see TakeawayDispatcher).
Schedule::command('takeaway:dispatch')->everyMinute()->withoutOverlapping();
