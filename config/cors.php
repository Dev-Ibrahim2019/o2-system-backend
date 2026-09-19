<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:4173', 'http://127.0.0.1:4173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // 0 يعني بلا تخزين مؤقت إطلاقاً — كل استدعاء GET/POST كان يُسبَق بطلب OPTIONS جديد
    // (preflight) في كل مرة، وهو ما ضاعف عدد الطلبات الفعلية عبر كامل التطبيق وليس فقط
    // نقطة التقرير. القيمة أدناه تسمح للمتصفح بتخزين نتيجة الـ preflight مؤقتاً (المتصفحات
    // تُطبِّق سقفها الخاص أصلاً — كروم يقصرها على ساعتين كحد أقصى بغض النظر عن القيمة هنا).
    'max_age' => 86400,

    'supports_credentials' => true,

];
