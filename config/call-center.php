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

    // طباعة تذاكر الأقسام تلقائيًا عند تنفيذ الطلب (فوري أو مجدول). لو false التذاكر بتنشأ بس بدون طباعة.
    'print_on_execute' => (bool) env('CALL_CENTER_PRINT_ON_EXECUTE', true),

    // Webhooks برنامج التيك أواي (TakeawayDispatcher): العنوان والسرّ لكل تطبيق بـ external_api_clients
    // (php artisan external-api:client create ...). هون بس مهلة الطلب وعدد المحاولات قبل ما الحدث يصير dead.
    'takeaway' => [
        'timeout' => (int) env('TAKEAWAY_WEBHOOK_TIMEOUT', 10),
        'max_attempts' => (int) env('TAKEAWAY_WEBHOOK_MAX_ATTEMPTS', 8),
    ],

    'slots' => [
        // السعة الافتراضية لكل فرع لو ما انضبطت branches.order_slot_capacity
        'default_capacity' => (int) env('CALL_CENTER_SLOTS_DEFAULT_CAPACITY', 200),
        // الخانة اللي تحررت للتو ما تنعطى لطلب جديد قبل مرور هالمدة — عشان ما يلتبس على الموظف
        // "كان هون طلب تاني قبل شوي".
        'reuse_cooldown_seconds' => (int) env('CALL_CENTER_SLOTS_REUSE_COOLDOWN', 60),
        'max_capacity' => 1000,
    ],
];
