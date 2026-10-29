<?php

/*
 * إعدادات وحدة المخازن الثابتة (التي لا تتغيّر من شاشة الإعدادات).
 * الإعدادات القابلة للتعديل من الواجهة (مثل السماح بالرصيد السالب) في جدول inventory_settings.
 */
return [
    /*
     * اليوم المحاسبي يبدأ 02:00 وينتهي 02:00 من اليوم التالي، بتوقيت الفروع.
     * توقيت التطبيق UTC، فالتحويل لتوقيت الفروع ضروري قبل حساب اليوم.
     * Asia/Gaza هو نفس التوقيت الذي تعتمده تقارير CRM (CrmReportController::BUSINESS_TZ).
     */
    'business_timezone' => env('INVENTORY_BUSINESS_TIMEZONE', 'Asia/Gaza'),
    'accounting_day_start' => env('INVENTORY_ACCOUNTING_DAY_START', '02:00'),
];
