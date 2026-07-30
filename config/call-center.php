<?php

$zones = json_decode((string) env('CALL_CENTER_DELIVERY_ZONES', '[]'), true);

return [
    /*
     * JSON array of operational delivery zones. No permissive fallback exists:
     * an address is rejected until an administrator configures a matching zone.
     * Example item:
     * {"id":1,"branch_id":2,"name":"رام الله","areas":["الماصيون"],"fee":10,"eta_minutes":35}
     */
    'delivery_zones' => is_array($zones) ? $zones : [],
    'quote_ttl_minutes' => (int) env('CALL_CENTER_DELIVERY_QUOTE_TTL', 15),
];
