<?php

namespace App\Providers;

use App\Models\Supplier;
use App\Observers\SupplierObserver;
use Illuminate\Support\ServiceProvider;

/**
 * ══════════════════════════════════════════════════════════════
 * AccountingServiceProvider — Subledger Version
 * ══════════════════════════════════════════════════════════════
 *
 * ما تغيّر:
 * - ❌ حذف Employee::observe(EmployeeObserver) —
 *      لم نعد ننشئ حسابات عند إنشاء الموظف
 *      (لا يوجد advance_account_id / salary_account_id)
 *
 * - ✅ Customer/Supplier يحتفظان بالـ Observer
 *      لأنهما ما زالا يستخدمان account_id لربط حساباتهم
 *      (يمكن تحويلهما لاحقاً لنفس نهج Subledger)
 *
 * - ✅ إضافة SubledgerService كـ Singleton
 * ══════════════════════════════════════════════════════════════
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ملاحظة: CustomerObserver و EmployeeObserver تم تعطيلهما
        // - العملاء: تحولوا إلى subledger بالكامل
        // - الموظفون: تحولوا إلى EmployeeAccountingService
        Supplier::observe(SupplierObserver::class);
    }

    public function register(): void
    {
        $this->app->singleton(\App\Services\Accounting\SubledgerService::class);
        $this->app->singleton(\App\Services\Accounting\AccountCreationService::class);
        $this->app->singleton(\App\Services\Accounting\TransactionPostingService::class);
        $this->app->singleton(\App\Services\Accounting\EmployeeAccountingService::class);
        $this->app->singleton(\App\Services\Accounting\CustomerAccountingService::class);
        $this->app->singleton(\App\Services\Accounting\SupplierAccountingService::class);

        // ── Sales Invoice Services ──
        $this->app->singleton(\App\Services\SalesInvoice\SalesInvoiceJournalService::class);
        $this->app->singleton(\App\Services\SalesInvoice\SalesInvoiceService::class);
        $this->app->singleton(\App\Services\SalesInvoice\SalesInvoiceExcelImportService::class);
        $this->app->singleton(\App\Services\SalesInvoice\PosSalesSyncService::class);
    }
}
