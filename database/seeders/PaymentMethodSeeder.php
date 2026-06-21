<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * PaymentMethodSeeder — Configurable Payment Routing
 *
 * Creates default payment methods linked to Chart of Accounts.
 * No hardcoded account numbers in the POS code — everything is configurable.
 *
 * Types:
 *   cash     → Main Cashbox (11101)
 *   bank     → Main Bank (11102)
 *   card     → Card Clearing (11103) — created if not exists
 *   wallet   → Digital Wallet (11104) — created if not exists
 *   customer → Accounts Receivable Control (1120) — subledger
 *   employee → Employee Advances Control (1130) — subledger
 *   supplier → Accounts Payable Control (2110) — subledger
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure card clearing and wallet accounts exist
        $this->ensureAccount('11103', 'حساب بطاقات الائتمان', 'Card Clearing Account', 'asset', 1110);
        $this->ensureAccount('11104', 'المحفظة الرقمية', 'Digital Wallet Account', 'asset', 1110);

        $accounts = [
            'cash'     => Account::where('code', '11101')->value('id'),
            'bank'     => Account::where('code', '11102')->value('id'),
            'card'     => Account::where('code', '11103')->value('id'),
            'wallet'   => Account::where('code', '11104')->value('id'),
            'customer' => Account::where('code', '1120')->value('id'),
            'employee' => Account::where('code', '1130')->value('id'),
            'supplier' => Account::where('code', '2110')->value('id'),
        ];

        $methods = [
            ['name' => 'نقداً',       'name_en' => 'Cash',         'type' => 'cash',     'account_id' => $accounts['cash'],     'is_entity' => false, 'sort_order' => 1],
            ['name' => 'تحويل بنكي',  'name_en' => 'Bank Transfer', 'type' => 'bank',     'account_id' => $accounts['bank'],     'is_entity' => false, 'sort_order' => 2],
            ['name' => 'بطاقة ائتمان', 'name_en' => 'Credit Card',  'type' => 'card',     'account_id' => $accounts['card'],     'is_entity' => false, 'sort_order' => 3],
            ['name' => 'محفظة رقمية', 'name_en' => 'Digital Wallet', 'type' => 'wallet',  'account_id' => $accounts['wallet'],   'is_entity' => false, 'sort_order' => 4],
            ['name' => 'حساب عميل',   'name_en' => 'Customer Account', 'type' => 'customer', 'account_id' => $accounts['customer'], 'is_entity' => true,  'sort_order' => 5],
            ['name' => 'حساب موظف',   'name_en' => 'Employee Account', 'type' => 'employee', 'account_id' => $accounts['employee'], 'is_entity' => true,  'sort_order' => 6],
            ['name' => 'حساب مورد',   'name_en' => 'Supplier Account', 'type' => 'supplier', 'account_id' => $accounts['supplier'], 'is_entity' => true,  'sort_order' => 7],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['type' => $method['type']],
                $method
            );
        }
    }

    private function ensureAccount(string $code, string $name, string $nameEn, string $type, int $parentCode): void
    {
        if (Account::where('code', $code)->exists()) {
            return;
        }

        $parent = Account::where('code', (string) $parentCode)->first();
        if (! $parent) return;

        Account::create([
            'code'           => $code,
            'name'           => $name,
            'name_en'        => $nameEn,
            'type'           => $type,
            'normal_balance' => 'debit',
            'level'          => 4,
            'parent_id'      => $parent->id,
            'allow_posting'  => true,
            'is_active'      => true,
            'is_system'      => true,
            'currency'       => 'ILS',
        ]);
    }
}
