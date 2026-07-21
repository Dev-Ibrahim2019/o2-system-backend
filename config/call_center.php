<?php
return [
    'webhook_secret' => env('CALL_CENTER_WEBHOOK_SECRET'),
    'trip_max_stops' => (int) env('DELIVERY_TRIP_MAX_STOPS', 3),
];
