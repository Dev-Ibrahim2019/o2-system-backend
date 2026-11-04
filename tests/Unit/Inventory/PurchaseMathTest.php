<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\InventoryRuleException;
use App\Services\Inventory\PurchaseMath;
use PHPUnit\Framework\TestCase;

/** قواعد حساب فاتورة المشتريات الموحّدة (بلا قاعدة بيانات). */
class PurchaseMathTest extends TestCase
{
    private static function stock(string $qty, string $price, string $pct = '0', string $tax = '0'): array
    {
        return ['kind' => 'stock', 'quantity' => $qty, 'unit_price' => $price, 'discount_pct' => $pct, 'tax_rate' => $tax];
    }

    public function test_line_total_is_quantity_times_price_minus_line_discount_plus_tax(): void
    {
        // 3 × 12.5 = 37.50، خصم 10% = 3.75 → 33.75، ضريبة 16% = 5.40 → 39.15
        $r = PurchaseMath::compute([self::stock('3', '12.5', '10', '16')], '0', true, '1');
        $l = $r['lines'][0];

        $this->assertSame('37.50', $l['gross']);
        $this->assertSame('3.75', $l['line_discount']);
        $this->assertSame('33.75', $l['taxable']);
        $this->assertSame('5.40', $l['tax']);
        $this->assertSame('39.15', $l['total']);
        $this->assertSame('39.15', $r['totals']['total']);
        $this->assertSame(1, $r['totals']['items_count']);
    }

    public function test_bill_discount_is_allocated_to_stock_lines_by_value(): void
    {
        // صافي الأصناف 500 + 100 + 100 = 700؛ خصم 10 → 7.14 + 1.43 + 1.43
        $r = PurchaseMath::compute([
            self::stock('10', '50'),
            self::stock('20', '5'),
            self::stock('4', '25'),
            ['kind' => 'expense', 'quantity' => '1', 'unit_price' => '30', 'account_id' => 9],
        ], '10', true, '1');

        $this->assertSame(['7.14', '1.43', '1.43', '0.00'], array_column($r['lines'], 'allocated_discount'));   // المصروف لا يأخذ منه
        $this->assertSame('492.86', $r['lines'][0]['taxable']);
        // الخصم يُخفّض تكلفة المخزون: 700 − 10
        $this->assertSame('690.00', $r['totals']['stock_cost']);
        $this->assertSame('720.00', $r['totals']['total']);
    }

    public function test_rounding_residual_of_bill_discount_goes_to_the_largest_line(): void
    {
        // ثلاثة أسطر متساوية وخصم 1.00 → 0.33 × 3 = 0.99، والسنت الباقي لأول أكبر سطر
        $r = PurchaseMath::compute([self::stock('1', '10'), self::stock('1', '10'), self::stock('1', '10')], '1', true, '1');

        $this->assertSame(['0.34', '0.33', '0.33'], array_column($r['lines'], 'allocated_discount'));
    }

    public function test_recoverable_tax_stays_out_of_stock_cost_and_goes_to_input_vat(): void
    {
        $r = PurchaseMath::compute([self::stock('10', '50', '0', '16')], '0', true, '1');

        $this->assertSame('500.00', $r['lines'][0]['cost_ils']);
        $this->assertSame('50.000000', $r['lines'][0]['unit_cost_ils']);
        $this->assertSame('500.00', $r['gl']['purchases']);
        $this->assertSame('80.00', $r['gl']['input_vat']);
        $this->assertSame('580.00', $r['gl']['payable']);
    }

    public function test_non_recoverable_tax_is_added_to_stock_cost(): void
    {
        $r = PurchaseMath::compute([self::stock('10', '50', '0', '16')], '0', false, '1');

        $this->assertSame('580.00', $r['lines'][0]['cost_ils']);
        $this->assertSame('58.000000', $r['lines'][0]['unit_cost_ils']);
        $this->assertSame('0.00', $r['gl']['input_vat']);
        $this->assertSame('580.00', $r['gl']['payable']);
    }

    public function test_foreign_currency_cost_and_ledger_are_in_shekels(): void
    {
        // السعر 4 منازل: 10.12345 → 10.1235؛ 2 × 10.1235 = 20.247 → 20.25؛ بالشيكل 20.25 × 3.65 = 73.9125 → 73.91
        $r = PurchaseMath::compute([self::stock('2', '10.12345')], '0', true, '3.65');

        $this->assertSame('10.1235', $r['lines'][0]['unit_price']);
        $this->assertSame('20.25', $r['lines'][0]['total']);
        $this->assertSame('73.91', $r['lines'][0]['cost_ils']);
        $this->assertSame('73.91', $r['gl']['payable']);
        $this->assertSame('36.956250', $r['lines'][0]['unit_cost_ils']);
    }

    public function test_expense_lines_debit_their_account_and_never_enter_stock(): void
    {
        $r = PurchaseMath::compute([
            self::stock('1', '100'),
            ['kind' => 'expense', 'quantity' => '1', 'unit_price' => '15', 'account_id' => 7, 'tax_rate' => '16'],
        ], '0', true, '1');

        $this->assertSame([7 => '15.00'], $r['gl']['expenses']);
        $this->assertSame('100.00', $r['totals']['stock_cost']);
        $this->assertSame('2.40', $r['gl']['input_vat']);
        $this->assertSame('117.40', $r['gl']['payable']);
        $this->assertSame('117.40', $r['totals']['total']);
    }

    public function test_bill_discount_larger_than_stock_value_is_rejected(): void
    {
        $this->expectException(InventoryRuleException::class);
        PurchaseMath::compute([self::stock('1', '10')], '11', true, '1');
    }

    public function test_round_half_up(): void
    {
        $this->assertSame('0.13', PurchaseMath::round('0.125', 2));
        $this->assertSame('-0.13', PurchaseMath::round('-0.125', 2));
        $this->assertSame('1.2346', PurchaseMath::round('1.23455', 4));
    }
}
