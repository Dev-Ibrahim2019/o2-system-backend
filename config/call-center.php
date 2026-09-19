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

    // الحد الأقصى الافتراضي لعدد التوصيلات النشطة المتزامنة لكل سائق — يُقرأ من هون بدل قيمة
    // مكتوبة صراحة بالكود، حتى يمكن رفعه لاحقًا (مثلاً لـ 2) بدون إعادة تصميم. يمكن تجاوزه لسائق
    // معيّن عبر employees.max_active_deliveries (Employee::maxActiveDeliveries()).
    'max_active_deliveries_per_driver' => (int) env('CALL_CENTER_MAX_ACTIVE_DELIVERIES_PER_DRIVER', 1),
];
