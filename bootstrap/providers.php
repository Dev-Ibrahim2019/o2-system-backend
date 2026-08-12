<?php

// bootstrap/providers.php
//
// ✅ الإصلاح المطبّق:
// إضافة AccountingServiceProvider الذي يتولى:
//   1. تسجيل EmployeeObserver / CustomerObserver / SupplierObserver
//      (بدونه لا تُنشأ الحسابات المحاسبية تلقائياً عند إنشاء الموظفين/العملاء/الموردين)
//   2. تسجيل AccountCreationService / TransactionPostingService /
//      EmployeeAccountingService / CustomerAccountingService / SupplierAccountingService
//      كـ Singletons (تُحسّن الأداء بمنع إنشاء instance جديدة لكل request)

use App\Providers\AppServiceProvider;
use App\Providers\AccountingServiceProvider;
use App\Providers\CustomerPortalServiceProvider;

$providers = [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    CustomerPortalServiceProvider::class,
];

// Telescope هو حزمة dev فقط (require-dev) — نسجّلها فقط إذا كانت مثبّتة فعلياً،
// حتى لا ينهار التطبيق لو تم نشره بدون dev dependencies (composer install --no-dev).
// الفلترة بين local/production نفسها معرّفة داخل App\Providers\TelescopeServiceProvider.
if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = \App\Providers\TelescopeServiceProvider::class;
}

return $providers;
