<?php


namespace App\Providers;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Supplier;
use App\Observers\AuditObserver;
use App\Observers\CustomerObserver;
use App\Observers\EmployeeObserver;
use App\Observers\SupplierObserver;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // تسجيل الـ Observers
        Employee::observe(EmployeeObserver::class);
        Customer::observe(CustomerObserver::class);
        Supplier::observe(SupplierObserver::class);
        // AuditObserver يُسجَّل داخل Trait::bootAuditable()
    }

    public function register(): void
    {
        // Bind Services كـ Singletons للأداء
        $this->app->singleton(
            \App\Services\Accounting\AccountCreationService::class
        );
        $this->app->singleton(
            \App\Services\Accounting\TransactionPostingService::class
        );
        $this->app->singleton(
            \App\Services\Accounting\EmployeeAccountingService::class
        );
        $this->app->singleton(
            \App\Services\Accounting\CustomerAccountingService::class
        );
        $this->app->singleton(
            \App\Services\Accounting\SupplierAccountingService::class
        );
    }
}
