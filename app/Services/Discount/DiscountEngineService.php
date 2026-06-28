<?php

namespace App\Services\Discount;

use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Models\DiscountUsageLog;
use App\Models\DiscountSetting;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * محرك الخصومات — قلب نظام إدارة الخصومات
 * 
 * المسؤوليات:
 * 1. البحث عن الخصومات المطبقة على سياق معين (عميل/موظف/مورد/قسم/صنف)
 * 2. تقييم الأولويات وتحديد الخصم الأنسب
 * 3. حساب الخصم على سعر صنف
 * 4. تسجيل استخدام الخصم
 * 5. التحقق من الصلاحية والشروط
 */
class DiscountEngineService
{
    /**
     * وضع الأولوية الافتراضي
     */
    private string $priorityMode;

    public function __construct()
    {
        $this->priorityMode = DiscountSetting::get('default_priority_mode', 'highest_first');
    }

    /**
     * البحث عن الخصومات المطبقة على عنصر في سياق معين
     * 
     * @param array $context سياق الفاتورة: customer_id, employee_id, supplier_id, department_id, item_id
     * @param int|null $branch_id
     * @return Collection<array{discount: Discount, discount_amount: float, final_price: float}>
     */
    public function findApplicableDiscounts(
        float $itemPrice,
        int $quantity = 1,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null,
        ?int $branchId = null
    ): Collection {
        // الحصول على جميع الخصومات النشطة
        $activeDiscounts = Discount::with('targets')
            ->active()
            ->byPriority()
            ->get();

        $applicable = collect();

        foreach ($activeDiscounts as $discount) {
            // التحقق من شروط إضافية (الحد الأدنى للطلب)
            if ($discount->min_order_amount && ($itemPrice * $quantity) < $discount->min_order_amount) {
                continue;
            }

            // التحقق من أن الخصم ينطبق على هذا السياق
            if ($this->discountAppliesToContext($discount, $customerId, $employeeId, $supplierId, $departmentId, $itemId)) {
                // حساب الخصم
                $discountAmount = $discount->calculateDiscount($itemPrice, $quantity);

                // تطبيق الحد الأقصى للخصم إذا وجد
                if ($discount->max_discount_amount && $discountAmount > $discount->max_discount_amount) {
                    $discountAmount = $discount->max_discount_amount;
                }

                $finalPrice = max(0, $itemPrice - $discountAmount);

                $applicable->push([
                    'discount' => $discount,
                    'discount_amount' => $discountAmount,
                    'original_price' => $itemPrice,
                    'final_price' => $finalPrice,
                    'discount_percent' => $discount->discount_type === 'percentage' ? $discount->value : null,
                ]);
            }
        }

        // تطبيق نظام الأولويات
        return $this->applyPriority($applicable);
    }

    /**
     * الحصول على أفضل خصم لعنصر في سياق معين
     * 
     * @return array{discount: Discount|null, discount_amount: float, original_price: float, final_price: float, discount_percent: float|null}|null
     */
    public function getBestDiscount(
        float $itemPrice,
        int $quantity = 1,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null,
        ?int $branchId = null
    ): ?array {
        $applicable = $this->findApplicableDiscounts(
            $itemPrice,
            $quantity,
            $customerId,
            $employeeId,
            $supplierId,
            $departmentId,
            $itemId,
            $branchId
        );

        if ($applicable->isEmpty()) {
            return null;
        }

        $best = $applicable->first();

        return [
            'discount' => $best['discount'],
            'discount_amount' => $best['discount_amount'],
            'original_price' => $best['original_price'],
            'final_price' => $best['final_price'],
            'discount_percent' => $best['discount_percent'],
        ];
    }

    /**
     * حساب إجمالي الخصم لمجموعة من أصناف السلة
     * 
     * @param array $items مصفوفة من العناصر: [{price, quantity, item_id, department_id}]
     * @return array{items: array, total_original: float, total_discount: float, total_final: float}
     */
    public function calculateCartDiscounts(
        array $items,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $branchId = null
    ): array {
        $totalOriginal = 0;
        $totalDiscount = 0;
        $totalFinal = 0;
        $processedItems = [];

        foreach ($items as $item) {
            $itemPrice = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $itemId = $item['item_id'] ?? null;
            $departmentId = $item['department_id'] ?? null;

            // الحصول على السعر الأصلي الإجمالي لهذا البند
            $lineOriginal = $itemPrice * $quantity;

            // البحث عن أفضل خصم
            $bestDiscount = $this->getBestDiscount(
                $itemPrice,
                $quantity,
                $customerId,
                $employeeId,
                $supplierId,
                $departmentId,
                $itemId,
                $branchId
            );

            if ($bestDiscount && $bestDiscount['discount']) {
                $lineDiscount = $bestDiscount['discount_amount'];
                $lineFinal = $bestDiscount['final_price'] * $quantity;

                $processedItems[] = [
                    'item_id' => $itemId,
                    'item_name' => $item['item_name'] ?? '',
                    'quantity' => $quantity,
                    'unit_price' => $itemPrice,
                    'original_price' => $itemPrice,
                    'original_total' => $lineOriginal,
                    'discount' => $bestDiscount['discount'],
                    'discount_amount' => $lineDiscount,
                    'discount_percent' => $bestDiscount['discount_percent'],
                    'final_unit_price' => $bestDiscount['final_price'],
                    'final_total' => $lineFinal,
                ];

                $totalDiscount += $lineDiscount;
            } else {
                $processedItems[] = [
                    'item_id' => $itemId,
                    'item_name' => $item['item_name'] ?? '',
                    'quantity' => $quantity,
                    'unit_price' => $itemPrice,
                    'original_price' => $itemPrice,
                    'original_total' => $lineOriginal,
                    'discount' => null,
                    'discount_amount' => 0,
                    'discount_percent' => null,
                    'final_unit_price' => $itemPrice,
                    'final_total' => $lineOriginal,
                ];
            }

            $totalOriginal += $lineOriginal;
            $totalFinal += $processedItems[count($processedItems) - 1]['final_total'];
        }

        return [
            'items' => $processedItems,
            'total_original' => $totalOriginal,
            'total_discount' => $totalDiscount,
            'total_final' => $totalFinal,
        ];
    }

    /**
     * تسجيل استخدام الخصم في قاعدة البيانات
     */
    public function logDiscountUsage(
        Discount $discount,
        float $originalPrice,
        float $discountAmount,
        float $finalPrice,
        ?float $discountPercent,
        ?int $invoiceId = null,
        ?int $invoiceItemId = null,
        ?int $orderId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $appliedBy = null,
        ?int $branchId = null
    ): DiscountUsageLog {
        return DiscountUsageLog::create([
            'discount_id' => $discount->id,
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $invoiceItemId,
            'order_id' => $orderId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'original_price' => $originalPrice,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'discount_percent' => $discountPercent,
            'applied_by' => $appliedBy,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * التحقق مما إذا كان الخصم ينطبق على سياق معين
     */
    protected function discountAppliesToContext(
        Discount $discount,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null
    ): bool {
        $targets = $discount->targets;

        // إذا لم يكن هناك مستهدفون، الخصم يسري على الجميع
        if ($targets->isEmpty()) {
            return true;
        }

        foreach ($targets as $target) {
            if ($this->targetMatches($target, $customerId, $employeeId, $supplierId, $departmentId, $itemId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق مما إذا كان مستهدف معين يطابق السياق الحالي
     */
    protected function targetMatches(
        DiscountTarget $target,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null
    ): bool {
        return match ($target->target_type) {
            'all' => true,
            'all_customers' => $customerId !== null,
            'all_employees' => $employeeId !== null,
            'all_suppliers' => $supplierId !== null,
            'customer' => $customerId !== null && (int) $target->target_id === $customerId,
            'employee' => $employeeId !== null && (int) $target->target_id === $employeeId,
            'supplier' => $supplierId !== null && (int) $target->target_id === $supplierId,
            'department' => $departmentId !== null && (int) $target->target_id === $departmentId,
            'item' => $itemId !== null && (int) $target->target_id === $itemId,
            default => false,
        };
    }

    /**
     * تطبيق نظام الأولويات على الخصومات المطبقة
     */
    protected function applyPriority(Collection $applicable): Collection
    {
        if ($applicable->isEmpty()) {
            return $applicable;
        }

        $allowCompound = DiscountSetting::get('allow_compound_discounts', 'true') === 'true';

        if (!$allowCompound) {
            // اختيار الخصم ذو الأولوية الأعلى فقط
            $best = $applicable->sortBy(function ($item) {
                // حسب الأولوية (الأصغر = الأهم)، ثم حسب قيمة الخصم
                return [$item['discount']->priority, -$item['discount_amount']];
            })->first();

            return collect([$best]);
        }

        // السماح بالخصومات المركبة — نرتب حسب الأولوية
        return $applicable->sortBy(function ($item) {
            return match ($this->priorityMode) {
                'lowest_first' => [$item['discount']->priority, $item['discount_amount']],
                'cumulative' => $item['discount']->priority,
                default => [$item['discount']->priority, -$item['discount_amount']], // highest_first
            };
        })->values();
    }

    /**
     * إعادة حساب أسعار أصناف السلة بناءً على الخصومات
     * (للدمج مع نظام السلة الحالي)
     */
    public function recalculateCartWithDiscounts(array $cartItems, ?int $customerId = null): array
    {
        $result = $this->calculateCartDiscounts($cartItems, $customerId);
        return $result['items'];
    }
}
