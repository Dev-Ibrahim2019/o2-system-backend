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

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    CustomerPortalServiceProvider::class,

];
