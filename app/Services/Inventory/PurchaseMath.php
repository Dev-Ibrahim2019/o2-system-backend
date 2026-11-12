<?php

namespace App\Services\Inventory;

/**
 * حساب فاتورة المشتريات — دوال صافية بلا قاعدة بيانات (عليها اختبارات الوحدة)، والواجهة تطبّق نفسها.
 *
 *   إجمالي السطر قبل الخصم = الكمية × السعر
 *   صافي السطر             = إجمالي السطر × (1 − خصم السطر%)
 *   خصم الفاتورة يوزَّع على الأسطر المخزنية فقط نسبةً إلى صافيها (فرق التقريب لأكبر سطر)، فيُخفّض تكلفتها
 *   الخاضع للضريبة         = الصافي − حصته من خصم الفاتورة
 *   الضريبة                = الخاضع × نسبة الضريبة
 *   إجمالي السطر           = الخاضع + الضريبة
 *   تكلفة المخزون (بالشيكل) = (الخاضع + الضريبة إن لم تكن قابلة للخصم) × سعر الصرف
 *
 * التقريب: الأسعار 4 منازل والمبالغ منزلتان (نصف للأعلى). تكلفة الوحدة الداخلة للمخزن 6 منازل.
 * القيد بالشيكل: مدين مشتريات/مصاريف/ضريبة مدخلات، ودائن المورّد = مجموع المدين، فيتوازن دائماً.
 */
final class PurchaseMath
{
    public const PRICE_SCALE = 4;

    public const AMOUNT_SCALE = 2;

    public const COST_SCALE = 6;

    /**
     * @param  array<int, array{kind: string, quantity: string|float|int, unit_price: string|float|int, discount_pct?: string|float|int|null, tax_rate?: string|float|int|null, account_id?: int|null}>  $lines
     * @return array{lines: array<int, array<string, string>>, totals: array<string, string|int>, gl: array{purchases: string, expenses: array<int, string>, input_vat: string, payable: string}}
     */
    public static function compute(array $lines, string|float|int $billDiscount, bool $taxRecoverable, string|float|int $exchangeRate): array
    {
        $billDiscount = self::round((string) $billDiscount, self::AMOUNT_SCALE);
        $rate = (string) $exchangeRate;
        if (bccomp($rate, '0', 8) <= 0) {
            throw new InventoryRuleException('سعر الصرف يجب أن يكون أكبر من صفر.');
        }

        $out = [];
        $stockNet = '0';
        foreach ($lines as $i => $l) {
            $qty = (string) $l['quantity'];
            $price = self::round((string) $l['unit_price'], self::PRICE_SCALE);
            $gross = self::round(bcmul($qty, $price, 10), self::AMOUNT_SCALE);
            $pct = (string) ($l['discount_pct'] ?? '0');
            if (bccomp($pct, '0', 4) < 0 || bccomp($pct, '100', 4) > 0) {
                throw new InventoryRuleException('السطر '.($i + 1).': نسبة الخصم بين 0 و 100.');
            }
            $lineDiscount = self::round(bcdiv(bcmul($gross, $pct, 10), '100', 10), self::AMOUNT_SCALE);
            $net = bcsub($gross, $lineDiscount, self::AMOUNT_SCALE);
            if ($l['kind'] === 'stock') {
                $stockNet = bcadd($stockNet, $net, self::AMOUNT_SCALE);
            }
            $out[$i] = ['kind' => $l['kind'], 'quantity' => $qty, 'unit_price' => $price, 'gross' => $gross,
                'line_discount' => $lineDiscount, 'net' => $net, 'allocated_discount' => '0.00'];
        }

        // توزيع خصم الفاتورة على الأسطر المخزنية نسبةً إلى صافيها
        if (bccomp($billDiscount, '0', 2) > 0) {
            if (bccomp($stockNet, '0', 2) <= 0) {
                throw new InventoryRuleException('خصم الفاتورة يوزَّع على الأصناف المخزنية — لا أصناف مخزنية في الفاتورة.');
            }
            if (bccomp($billDiscount, $stockNet, 2) > 0) {
                throw new InventoryRuleException('خصم الفاتورة أكبر من صافي الأصناف المخزنية.');
            }
            $allocated = '0';
            $largest = null;
            foreach ($out as $i => $l) {
                if ($l['kind'] !== 'stock') {
                    continue;
                }
                $share = self::round(bcdiv(bcmul($billDiscount, $l['net'], 12), $stockNet, 12), self::AMOUNT_SCALE);
                $out[$i]['allocated_discount'] = $share;
                $allocated = bcadd($allocated, $share, 2);
                if ($largest === null || bccomp($l['net'], $out[$largest]['net'], 2) > 0) {
                    $largest = $i;
                }
            }
            $residual = bcsub($billDiscount, $allocated, 2);
            $out[$largest]['allocated_discount'] = bcadd($out[$largest]['allocated_discount'], $residual, 2);
        }

        $gl = ['purchases' => '0.00', 'expenses' => [], 'input_vat' => '0.00'];
        $t = ['items_count' => 0, 'gross' => '0.00', 'line_discounts' => '0.00', 'bill_discount' => $billDiscount,
            'net_before_tax' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'stock_cost' => '0.00'];
        foreach ($out as $i => $l) {
            $taxRate = (string) ($lines[$i]['tax_rate'] ?? '0');
            $taxable = bcsub($l['net'], $l['allocated_discount'], 2);
            $tax = self::round(bcdiv(bcmul($taxable, $taxRate, 10), '100', 10), self::AMOUNT_SCALE);
            $total = bcadd($taxable, $tax, 2);
            // الضريبة القابلة للخصم لا تدخل التكلفة؛ غير القابلة تُحمَّل على الصنف/المصروف
            $costBase = $taxRecoverable ? $taxable : $total;
            $costIls = self::round(bcmul($costBase, $rate, 10), self::AMOUNT_SCALE);
            $unitCostIls = bccomp($l['quantity'], '0', 6) > 0
                ? self::round(bcdiv(bcmul($costBase, $rate, 12), $l['quantity'], 12), self::COST_SCALE)
                : '0';
            $out[$i] += ['tax_rate' => $taxRate, 'taxable' => $taxable, 'tax' => $tax, 'total' => $total,
                'cost_ils' => $costIls, 'unit_cost_ils' => $unitCostIls];

            if ($l['kind'] === 'stock') {
                $t['items_count']++;
                $t['stock_cost'] = bcadd($t['stock_cost'], $costIls, 2);
                $gl['purchases'] = bcadd($gl['purchases'], $costIls, 2);
            } else {
                $account = (int) ($lines[$i]['account_id'] ?? 0);
                $gl['expenses'][$account] = bcadd($gl['expenses'][$account] ?? '0', $costIls, 2);
            }
            if ($taxRecoverable) {
                $gl['input_vat'] = bcadd($gl['input_vat'], self::round(bcmul($tax, $rate, 10), self::AMOUNT_SCALE), 2);
            }
            $t['gross'] = bcadd($t['gross'], $l['gross'], 2);
            $t['line_discounts'] = bcadd($t['line_discounts'], $l['line_discount'], 2);
            $t['net_before_tax'] = bcadd($t['net_before_tax'], $taxable, 2);
            $t['tax'] = bcadd($t['tax'], $tax, 2);
            $t['total'] = bcadd($t['total'], $total, 2);
        }
        // المصاريف المحمّلة (نقل، جمارك...): أسطر مصاريف عليها allocate_to_stock تُوزَّع على الأسطر المخزنية بنسبة قيمتها
        // (الخاضع بعد الخصم)، فتدخل تكلفة الدفعة. القيد لا يتغير (المصروف على حسابه).
        $landed = '0';
        $stockBase = '0';
        $largest = null;
        foreach ($out as $i => $l) {
            $out[$i]['allocated_expense'] = '0.00';
            if ($l['kind'] !== 'stock' && ! empty($lines[$i]['allocate_to_stock'])) {
                $landed = bcadd($landed, $l['cost_ils'], 2);
            } elseif ($l['kind'] === 'stock') {
                $stockBase = bcadd($stockBase, $l['taxable'], 2);
                if ($largest === null || bccomp($l['taxable'], $out[$largest]['taxable'], 2) > 0) {
                    $largest = $i;
                }
            }
        }
        if (bccomp($landed, '0', 2) > 0) {
            if ($largest === null || bccomp($stockBase, '0', 2) <= 0) {
                throw new InventoryRuleException('المصاريف المحمّلة على الأصناف تحتاج أصنافاً مخزنية بقيمة في الفاتورة.');
            }
            $allocated = '0';
            foreach ($out as $i => $l) {
                if ($l['kind'] !== 'stock') {
                    continue;
                }
                $share = self::round(bcdiv(bcmul($landed, $l['taxable'], 12), $stockBase, 12), self::AMOUNT_SCALE);
                $out[$i]['allocated_expense'] = $share;
                $allocated = bcadd($allocated, $share, 2);
            }
            $out[$largest]['allocated_expense'] = bcadd($out[$largest]['allocated_expense'], bcsub($landed, $allocated, 2), 2);
            foreach ($out as $i => $l) {
                if ($l['kind'] !== 'stock' || bccomp($l['quantity'], '0', 6) <= 0) {
                    continue;
                }
                // تكلفة الوحدة = (تكلفة السطر بالشيكل + حصته من المصاريف) ÷ الكمية
                $costBase = $taxRecoverable ? $l['taxable'] : $l['total'];
                $out[$i]['unit_cost_ils'] = self::round(
                    bcdiv(bcadd(bcmul($costBase, $rate, 12), $l['allocated_expense'], 12), $l['quantity'], 12), self::COST_SCALE);
            }
        }

        // دائن المورّد = مجموع المدين بالشيكل (يتوازن القيد مهما كان تقريب سعر الصرف)
        $gl['payable'] = array_reduce($gl['expenses'], fn ($c, $v) => bcadd($c, $v, 2), bcadd($gl['purchases'], $gl['input_vat'], 2));

        return ['lines' => $out, 'totals' => $t, 'gl' => $gl];
    }

    /** المبلغ بالشيكل لدفعة بعملة الفاتورة. */
    public static function toIls(string|float|int $amount, string|float|int $rate): string
    {
        return self::round(bcmul((string) $amount, (string) $rate, 10), self::AMOUNT_SCALE);
    }

    /** تقريب نصف للأعلى (بعيداً عن الصفر) إلى $scale منزلة. */
    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', 12) >= 0 ? bcadd($value, $half, $scale) : bcsub($value, $half, $scale);
    }
}
