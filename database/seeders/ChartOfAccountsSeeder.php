<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════
 * Chart of Accounts Seeder — Subledger Architecture
 * ═══════════════════════════════════════════════════════════════
 *
 * النظام الجديد:
 *
 * ❌ لا يوجد حساب مستقل لكل موظف/عميل/مورد
 * ❌ لا يوجد:
 *      1120-1
 *      1120-2
 *      1130-5
 *
 * ✅ يوجد Control Account فقط:
 *      1120 → ذمم العملاء
 *      1130 → سلف الموظفين
 *      2110 → ذمم الموردين
 *      2120 → رواتب مستحقة
 *
 * والتفصيل يتم عبر:
 *      entries.subledger_type
 *      entries.subledger_id
 *
 * مثال:
 *
 * account_id      = 1120
 * subledger_type  = customer
 * subledger_id    = 55
 *
 * هذا التصميم هو المستخدم في أنظمة ERP الحقيقية.
 * ═══════════════════════════════════════════════════════════════
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $this->createAssets();
        $this->createLiabilities();
        $this->createEquity();
        $this->createRevenue();
        $this->createExpenses();
    }

    // ══════════════════════════════════════════════════════════
    // 1. ASSETS — الأصول
    // ══════════════════════════════════════════════════════════
    private function createAssets(): void
    {
        $assets = $this->create([
            'code'           => '1',
            'name'           => 'الأصول',
            'name_en'        => 'Assets',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 1,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 11 Current Assets
        // ─────────────────────────────────────────────────────

        $currentAssets = $this->create([
            'code'           => '11',
            'name'           => 'الأصول المتداولة',
            'name_en'        => 'Current Assets',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 2,
            'parent_id'      => $assets->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 1110 Cash & Bank
        // ─────────────────────────────────────────────────────

        $cashParent = $this->create([
            'code'           => '1110',
            'name'           => 'النقد والبنوك',
            'name_en'        => 'Cash & Bank',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $currentAssets->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '11101',
            'name'           => 'الصندوق الرئيسي',
            'name_en'        => 'Main Cash',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 4,
            'parent_id'      => $cashParent->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '11102',
            'name'           => 'البنك الرئيسي',
            'name_en'        => 'Main Bank',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 4,
            'parent_id'      => $cashParent->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 1120 Accounts Receivable
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '1120',
            'name'           => 'ذمم العملاء المدينة',
            'name_en'        => 'Accounts Receivable',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $currentAssets->id,

            // مهم جداً
            'allow_posting'  => true,

            'is_system'      => true,

            'meta' => [
                'subledger'  => true,
                'entity_type' => 'customer',
            ],
        ]);

        // ─────────────────────────────────────────────────────
        // 1130 Employee Advances
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '1130',
            'name'           => 'سلف الموظفين',
            'name_en'        => 'Employee Advances',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $currentAssets->id,

            'allow_posting'  => true,

            'is_system'      => true,

            'meta' => [
                'subledger'   => true,
                'entity_type' => 'employee',
            ],
        ]);

        // ─────────────────────────────────────────────────────
        // 1140 Prepaid Expenses
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '1140',
            'name'           => 'مصاريف مدفوعة مقدماً',
            'name_en'        => 'Prepaid Expenses',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $currentAssets->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 12 Non-Current Assets
        // ─────────────────────────────────────────────────────

        $nonCurrentAssets = $this->create([
            'code'           => '12',
            'name'           => 'الأصول غير المتداولة',
            'name_en'        => 'Non-Current Assets',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 2,
            'parent_id'      => $assets->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '1210',
            'name'           => 'الأصول الثابتة',
            'name_en'        => 'Fixed Assets',
            'type'           => 'asset',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $nonCurrentAssets->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 2. LIABILITIES — الالتزامات
    // ══════════════════════════════════════════════════════════

    private function createLiabilities(): void
    {
        $liabilities = $this->create([
            'code'           => '2',
            'name'           => 'الالتزامات',
            'name_en'        => 'Liabilities',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 1,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $currentLiab = $this->create([
            'code'           => '21',
            'name'           => 'الالتزامات المتداولة',
            'name_en'        => 'Current Liabilities',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 2,
            'parent_id'      => $liabilities->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 2110 Accounts Payable
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '2110',
            'name'           => 'ذمم الموردين الدائنة',
            'name_en'        => 'Accounts Payable',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 3,
            'parent_id'      => $currentLiab->id,

            'allow_posting'  => true,

            'is_system'      => true,

            'meta' => [
                'subledger'   => true,
                'entity_type' => 'supplier',
            ],
        ]);

        // ─────────────────────────────────────────────────────
        // 2120 Salaries Payable
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '2120',
            'name'           => 'الرواتب المستحقة',
            'name_en'        => 'Salaries Payable',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 3,
            'parent_id'      => $currentLiab->id,

            'allow_posting'  => true,

            'is_system'      => true,

            'meta' => [
                'subledger'   => true,
                'entity_type' => 'employee',
            ],
        ]);

        // ─────────────────────────────────────────────────────
        // 2130 Employee Loans
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '2130',
            'name'           => 'قروض الموظفين',
            'name_en'        => 'Employee Loans',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 3,
            'parent_id'      => $currentLiab->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        // ─────────────────────────────────────────────────────
        // 2140 Tax Payable
        // ─────────────────────────────────────────────────────

        $this->create([
            'code'           => '2140',
            'name'           => 'ضرائب مستحقة',
            'name_en'        => 'Tax Payable',
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'level'          => 3,
            'parent_id'      => $currentLiab->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 3. EQUITY
    // ══════════════════════════════════════════════════════════

    private function createEquity(): void
    {
        $equity = $this->create([
            'code'           => '3',
            'name'           => 'حقوق الملكية',
            'name_en'        => 'Equity',
            'type'           => 'equity',
            'normal_balance' => 'credit',
            'level'          => 1,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '3110',
            'name'           => 'رأس المال',
            'name_en'        => 'Capital',
            'type'           => 'equity',
            'normal_balance' => 'credit',
            'level'          => 2,
            'parent_id'      => $equity->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '3120',
            'name'           => 'الأرباح المحتجزة',
            'name_en'        => 'Retained Earnings',
            'type'           => 'equity',
            'normal_balance' => 'credit',
            'level'          => 2,
            'parent_id'      => $equity->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 4. REVENUE
    // ══════════════════════════════════════════════════════════

    private function createRevenue(): void
    {
        $revenue = $this->create([
            'code'           => '4',
            'name'           => 'الإيرادات',
            'name_en'        => 'Revenue',
            'type'           => 'revenue',
            'normal_balance' => 'credit',
            'level'          => 1,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '4110',
            'name'           => 'إيرادات المبيعات',
            'name_en'        => 'Sales Revenue',
            'type'           => 'revenue',
            'normal_balance' => 'credit',
            'level'          => 2,
            'parent_id'      => $revenue->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 5. EXPENSES
    // ══════════════════════════════════════════════════════════

    private function createExpenses(): void
    {
        $expenses = $this->create([
            'code'           => '5',
            'name'           => 'المصاريف',
            'name_en'        => 'Expenses',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 1,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $staffCosts = $this->create([
            'code'           => '51',
            'name'           => 'تكاليف الموارد البشرية',
            'name_en'        => 'Staff Costs',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 2,
            'parent_id'      => $expenses->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '5110',
            'name'           => 'مصروف الرواتب والأجور',
            'name_en'        => 'Salaries Expense',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $staffCosts->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '5120',
            'name'           => 'مصروف التأمينات الاجتماعية',
            'name_en'        => 'Social Insurance Expense',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $staffCosts->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);

        $operatingExp = $this->create([
            'code'           => '52',
            'name'           => 'المصاريف التشغيلية',
            'name_en'        => 'Operating Expenses',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 2,
            'parent_id'      => $expenses->id,
            'allow_posting'  => false,
            'is_system'      => true,
        ]);

        $this->create([
            'code'           => '5210',
            'name'           => 'مصروف الإيجار',
            'name_en'        => 'Rent Expense',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'level'          => 3,
            'parent_id'      => $operatingExp->id,
            'allow_posting'  => true,
            'is_system'      => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // Helper
    // ══════════════════════════════════════════════════════════

    private function create(array $data): Account
    {
        return Account::create(array_merge([
            'is_active' => true,
            'currency'  => 'ILS',
            'is_system' => false,
        ], $data));
    }
}
