<?php

// ══════════════════════════════════════════════════════════════════════
// OBSERVER: EmployeeObserver
// ══════════════════════════════════════════════════════════════════════
// يُطلق تلقائياً عند إنشاء/حذف/تعديل الموظف
// لماذا Observer وليس Controller؟
//   - يضمن إنشاء الحسابات حتى لو أُنشئ الموظف من أي مكان (CLI, seeder, import)
//   - يفصل المسؤوليات: Controller لا يعرف شيئاً عن المحاسبة

namespace App\Observers;

use App\Models\Employee;
use App\Services\Accounting\AccountCreationService;
use Illuminate\Support\Facades\Log;

class EmployeeObserver
{
    public function __construct(
        private readonly AccountCreationService $accountCreationService,
    ) {}

    /**
     * بعد إنشاء الموظف: أنشئ حسابيه المحاسبيين تلقائياً
     */
    public function created(Employee $employee): void
    {
        try {
            $this->accountCreationService->createForEmployee($employee);
        } catch (\Throwable $e) {
            // لا نُفشل إنشاء الموظف بسبب خطأ محاسبي
            // لكن نُسجّل الخطأ لمعالجته لاحقاً
            Log::error("فشل إنشاء حسابات الموظف [{$employee->id}]: {$e->getMessage()}");
        }
    }

    /**
     * بعد حذف الموظف: عطّل حساباته (لا تحذفها — البيانات المالية ضرورية)
     */
    public function deleted(Employee $employee): void
    {
        try {
            $this->accountCreationService->deactivateEmployeeAccounts($employee);
        } catch (\Throwable $e) {
            Log::error("فشل تعطيل حسابات الموظف [{$employee->id}]: {$e->getMessage()}");
        }
    }
}
