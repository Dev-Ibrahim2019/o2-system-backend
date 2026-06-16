<?php

// ══════════════════════════════════════════════════════════════════════
// SERVICE: AccountCreationService
// ══════════════════════════════════════════════════════════════════════
// مسؤولية واحدة: إنشاء الحسابات المحاسبية الفرعية للكيانات
// لا يحتوي على منطق قيود — ذلك في TransactionPostingService

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountCreationService
{
    /**
     * ⚠️ تم إلغاء إنشاء حسابات الموظف الفرعية — تم الانتقال إلى subledger بالكامل.
     *
     * جميع أرصدة الموظفين تُحسب عبر:
     *   entries.subledger_type = 'employee'
     *   entries.subledger_id   = {employee_id}
     *
     * حسابات التحكم المستخدمة:
     *   - 1130 (سلف الموظفين)
     *   - 2120 (رواتب مستحقة)
     *   - 2130 (قروض الموظفين)
     *   - 5110 (مصروف رواتب)
     *
     * تم إيقاف هذه الدالة نهائياً. لا يُستخدم حساب GL منفصل لكل موظف.
     *
     * @deprecated استخدام subledger بدلاً من حسابات GL فردية
     */
    public function createForEmployee(Employee $employee): ?array
    {
        return null; // تم الانتقال إلى subledger بالكامل
    }

    /**
     * ⚠️ تم إلغاء إنشاء حساب العميل الفرعي — تم الانتقال إلى subledger بالكامل.
     *
     * جميع أرصدة العملاء تُحسب عبر:
     *   entries.subledger_type = 'customer'
     *   entries.subledger_id   = {customer_id}
     *
     * تم إيقاف هذه الدالة نهائياً. لا يُستخدم حساب GL منفصل لكل عميل.
     *
     * @deprecated استخدام subledger بدلاً من حسابات GL فردية
     */
    public function createForCustomer(Customer $customer): ?Account
    {
        return null; // تم الانتقال إلى subledger بالكامل
    }

    /**
     * ⚠️ تم إلغاء إنشاء حساب المورد الفرعي — تم الانتقال إلى subledger بالكامل.
     *
     * جميع أرصدة الموردين تُحسب عبر:
     *   entries.subledger_type = 'supplier'
     *   entries.subledger_id   = {supplier_id}
     *
     * تم إيقاف هذه الدالة نهائياً. لا يُستخدم حساب GL منفصل لكل مورد.
     *
     * @deprecated استخدام subledger بدلاً من حسابات GL فردية
     */
    public function createForSupplier(Supplier $supplier): ?Account
    {
        return null; // تم الانتقال إلى subledger بالكامل
    }

    /**
     * تعطيل حسابات الموظف عند إنهاء الخدمة
     * لا نحذف — البيانات المالية يجب الاحتفاظ بها
     */
    public function deactivateEmployeeAccounts(Employee $employee): void
    {
        Account::whereIn('id', array_filter([
            $employee->advance_account_id,
            $employee->salary_account_id,
        ]))->update(['is_active' => false]);
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────

    private function createSubAccount(
        string  $parentCode,
        string  $entityType,
        int     $entityId,
        ?string $subType,
        string  $name,
        string  $nameEn,
        string  $accountType,
    ): Account {
        // منع التكرار — إذا كان الحساب موجوداً نُرجعه
        $existing = Account::forEntity($entityType, $entityId, $subType)->first();
        if ($existing) {
            return $existing;
        }

        $parent = Account::where('code', $parentCode)->firstOrFail();

        if (! $parent->is_active) {
            throw new RuntimeException("الحساب الأم {$parentCode} غير نشط");
        }

        $code = $this->generateSubAccountCode($parent);

        return Account::create([
            'name'           => $name,
            'name_en'        => $nameEn,
            'code'           => $code,
            'type'           => $accountType,
            'normal_balance' => in_array($accountType, ['asset', 'expense']) ? 'debit' : 'credit',
            'parent_id'      => $parent->id,
            'level'          => $parent->level + 1,
            'allow_posting'  => true,
            'is_active'      => true,
            'is_system'      => false,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'sub_type'       => $subType,
            'currency'       => config('app.default_currency', 'ILS'),
        ]);
    }

    /**
     * توليد كود فريد للحساب الفرعي
     * مثال: parent=1130 → 113001, 113002, ...
     * يستخدم DB lock لمنع race conditions في high concurrency
     */
    private function generateSubAccountCode(Account $parent): string
    {
        // LOCK FOR UPDATE — يمنع قراءة نفس آخر كود من threads متزامنة
        $lastAccount = Account::where('code', 'like', $parent->code . '%')
            ->where('code', '!=', $parent->code)
            ->lockForUpdate()
            ->orderByDesc('code')
            ->first();

        if ($lastAccount) {
            $suffix = (int) substr($lastAccount->code, strlen($parent->code));
            $next   = $suffix + 1;
        } else {
            $next = 1;
        }

        return $parent->code . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
