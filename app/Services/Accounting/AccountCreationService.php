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
     * ──────────────────────────────────────────────────────────────
     * إنشاء حسابات الموظف (اثنان منفصلان)
     * ──────────────────────────────────────────────────────────────
     *
     * نُنشئ حسابين لكل موظف:
     * 1. سلف الموظف (1130-xxx)  ← Asset   ← مدين
     * 2. راتب الموظف  (2120-xxx) ← Liability ← دائن
     *
     * الفصل ضروري محاسبياً لأن:
     * - السلفة: المال خرج من الشركة → أصل مستحق الاسترداد
     * - الراتب: التزام على الشركة → يجب دفعه للموظف
     * خلطهما يُخل بالميزانية العمومية
     */
    public function createForEmployee(Employee $employee): array
    {
        return DB::transaction(function () use ($employee) {
            $advanceAccount = $this->createSubAccount(
                parentCode: '1130',
                entityType: 'employee',
                entityId: $employee->id,
                subType: 'advance',
                name: "سلف الموظف: {$employee->name}",
                nameEn: "Employee Advance: {$employee->name}",
                accountType: 'asset',
            );

            $salaryAccount = $this->createSubAccount(
                parentCode: '2120',
                entityType: 'employee',
                entityId: $employee->id,
                subType: 'salary',
                name: "راتب الموظف: {$employee->name}",
                nameEn: "Salary Payable: {$employee->name}",
                accountType: 'liability',
            );

            $employee->update([
                'advance_account_id' => $advanceAccount->id,
                'salary_account_id'  => $salaryAccount->id,
            ]);

            return [
                'advance_account' => $advanceAccount,
                'salary_account'  => $salaryAccount,
            ];
        });
    }

    /**
     * إنشاء حساب العميل تحت Accounts Receivable (1120)
     */
    public function createForCustomer(Customer $customer): Account
    {
        return DB::transaction(function () use ($customer) {
            $account = $this->createSubAccount(
                parentCode: '1120',
                entityType: 'customer',
                entityId: $customer->id,
                subType: 'default',
                name: "ذمة العميل: {$customer->name}",
                nameEn: "AR: {$customer->name}",
                accountType: 'asset',
            );

            $customer->update(['account_id' => $account->id]);

            return $account;
        });
    }

    /**
     * إنشاء حساب المورد تحت Accounts Payable (2110)
     */
    public function createForSupplier(Supplier $supplier): Account
    {
        return DB::transaction(function () use ($supplier) {
            $account = $this->createSubAccount(
                parentCode: '2110',
                entityType: 'supplier',
                entityId: $supplier->id,
                subType: 'default',
                name: "ذمة المورد: {$supplier->name}",
                nameEn: "AP: {$supplier->name}",
                accountType: 'liability',
            );

            $supplier->update(['account_id' => $account->id]);

            return $account;
        });
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
