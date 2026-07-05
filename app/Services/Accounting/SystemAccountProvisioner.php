<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\DiscountSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures required system GL accounts exist before posting journal entries.
 */
class SystemAccountProvisioner
{
    private const REVENUE_CODE = '4110';

    private const SALES_DISCOUNTS_CODE = '4120';

    public function ensureSalesRevenueAccount(): Account
    {
        return $this->ensureAccount(
            self::REVENUE_CODE,
            'إيرادات المبيعات',
            'Sales Revenue',
            'revenue',
            'credit',
            '4'
        );
    }

    public function ensureSalesDiscountsAccount(): Account
    {
        $code = DiscountSetting::getSalesDiscountsAccountCode() ?: self::SALES_DISCOUNTS_CODE;

        return $this->ensureAccount(
            $code,
            'خصومات المبيعات',
            'Sales Discounts',
            'revenue',
            'debit',
            '4'
        );
    }

    private function ensureAccount(
        string $code,
        string $name,
        string $nameEn,
        string $type,
        string $normalBalance,
        string $parentCode
    ): Account {
        $existing = Account::where('code', $code)->first();
        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true, 'allow_posting' => true]);
            }

            return $existing->fresh();
        }

        $parent = Account::where('code', $parentCode)->first();
        if (! $parent && Schema::hasTable('accounts')) {
            throw new \RuntimeException("الحساب الأب {$parentCode} غير موجود — شغّل ChartOfAccountsSeeder أولاً.");
        }

        return Account::create([
            'code' => $code,
            'name' => $name,
            'name_en' => $nameEn,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'level' => ($parent?->level ?? 1) + 1,
            'parent_id' => $parent?->id,
            'allow_posting' => true,
            'is_active' => true,
            'is_system' => true,
            'currency' => 'ILS',
        ]);
    }
}
