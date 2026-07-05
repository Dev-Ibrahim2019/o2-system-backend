<?php

namespace App\Services\Discount;

use App\Models\Discount;
use App\Models\DiscountSetting;
use App\Models\DiscountTarget;
use App\Models\DiscountUsageLog;
use Illuminate\Support\Collection;

class DiscountEngineService
{
    private string $priorityMode;

    public function __construct()
    {
        $this->priorityMode = DiscountSetting::get('default_priority_mode', 'highest_first');
    }

    public function findApplicableDiscounts(
        float $itemPrice,
        int $quantity = 1,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null,
        ?int $branchId = null,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?int $modifierId = null,
        ?float $invoiceSubtotal = null
    ): Collection {
        return collect($this->evaluateDiscounts(
            $itemPrice,
            $quantity,
            $customerId,
            $employeeId,
            $supplierId,
            $departmentId,
            $itemId,
            $branchId,
            $categoryId,
            $brandId,
            $modifierId,
            $invoiceSubtotal
        )['matched']);
    }

    public function getBestDiscount(
        float $itemPrice,
        int $quantity = 1,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null,
        ?int $branchId = null,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?int $modifierId = null,
        ?float $invoiceSubtotal = null
    ): ?array {
        $applicable = $this->findApplicableDiscounts(
            $itemPrice,
            $quantity,
            $customerId,
            $employeeId,
            $supplierId,
            $departmentId,
            $itemId,
            $branchId,
            $categoryId,
            $brandId,
            $modifierId,
            $invoiceSubtotal
        );

        return $applicable->first();
    }

    public function calculateCartDiscounts(
        array $items,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $branchId = null
    ): array {
        $totalOriginal = 0.0;
        foreach ($items as $item) {
            $totalOriginal += (float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }

        $totalDiscount = 0.0;
        $processedItems = [];

        foreach ($items as $item) {
            $unitPrice = (float) ($item['price'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineOriginal = $unitPrice * $quantity;

            $bestDiscount = $this->getBestDiscount(
                $unitPrice,
                $quantity,
                $customerId,
                $employeeId,
                $supplierId,
                $item['department_id'] ?? null,
                $item['item_id'] ?? null,
                $branchId,
                $item['category_id'] ?? null,
                $item['brand_id'] ?? null,
                $item['modifier_id'] ?? null,
                $totalOriginal
            );

            $lineDiscount = $bestDiscount ? (float) $bestDiscount['discount_amount'] : 0.0;
            $lineFinal = max(0, $lineOriginal - $lineDiscount);

            $processedItems[] = [
                'item_id' => $item['item_id'] ?? null,
                'item_name' => $item['item_name'] ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'original_price' => $unitPrice,
                'original_total' => round($lineOriginal, 3),
                'discount' => $bestDiscount['discount'] ?? null,
                'discount_amount' => round($lineDiscount, 3),
                'discount_percent' => $bestDiscount['discount_percent'] ?? null,
                'apply_strategy' => $bestDiscount['apply_strategy'] ?? null,
                'final_unit_price' => round($lineFinal / $quantity, 3),
                'final_total' => round($lineFinal, 3),
            ];

            $totalDiscount += $lineDiscount;
        }

        return [
            'items' => $processedItems,
            'total_original' => round($totalOriginal, 3),
            'total_discount' => round($totalDiscount, 3),
            'total_final' => round(max(0, $totalOriginal - $totalDiscount), 3),
        ];
    }

    public function evaluateDiscounts(
        float $itemPrice,
        int $quantity = 1,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $supplierId = null,
        ?int $departmentId = null,
        ?int $itemId = null,
        ?int $branchId = null,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?int $modifierId = null,
        ?float $invoiceSubtotal = null
    ): array {
        $quantity = max(1, $quantity);
        $context = compact(
            'customerId',
            'employeeId',
            'supplierId',
            'departmentId',
            'itemId',
            'branchId',
            'categoryId',
            'brandId',
            'modifierId'
        );

        $discounts = Discount::with(['targets', 'exclusions'])
            ->active()
            ->byPriority()
            ->get();

        $matched = collect();
        $rejected = [];
        $excluded = [];

        foreach ($discounts as $discount) {
            $lineOriginal = $itemPrice * $quantity;

            if ($discount->min_order_amount && $lineOriginal < (float) $discount->min_order_amount) {
                $rejected[] = $this->ruleSummary($discount, 'Minimum order amount not reached.');
                continue;
            }

            $matchedExclusion = $discount->exclusions->first(
                fn($target) => $this->targetMatches($target, $context)
            );

            if ($matchedExclusion) {
                $excluded[] = $this->ruleSummary(
                    $discount,
                    "Excluded by {$matchedExclusion->target_type} {$matchedExclusion->target_id}."
                );
                continue;
            }

            if (! $this->discountAppliesToContext($discount, $context)) {
                $rejected[] = $this->ruleSummary($discount, 'Targets did not match the current context.');
                continue;
            }

            $lineDiscount = $discount->calculateLineDiscount($itemPrice, $quantity, $invoiceSubtotal);
            $lineDiscount = min($lineDiscount, $lineOriginal);

            if ($discount->max_discount_amount && $lineDiscount > (float) $discount->max_discount_amount) {
                $lineDiscount = (float) $discount->max_discount_amount;
            }

            $matched->push([
                'discount' => $discount,
                'discount_amount' => round($lineDiscount, 3),
                'original_price' => round($lineOriginal, 3),
                'final_price' => round(max(0, $lineOriginal - $lineDiscount), 3),
                'discount_percent' => $discount->discount_type === 'percentage' ? (float) $discount->value : null,
                'apply_strategy' => $discount->apply_strategy ?? 'per_quantity',
                'reason' => 'Targets matched and no exclusion rule applied.',
            ]);
        }

        return [
            'matched' => $this->applyPriority($matched)->values()->all(),
            'rejected' => $rejected,
            'excluded' => $excluded,
        ];
    }

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

    protected function discountAppliesToContext(Discount $discount, array $context): bool
    {
        $targets = $discount->targets;

        if ($targets->isEmpty()) {
            return true;
        }

        foreach ($targets as $target) {
            if (! $this->targetMatches($target, $context)) {
                return false;
            }
        }

        return true;
    }

    protected function targetMatches(object $target, array $context): bool
    {
        $type = $target->target_type;
        $id = $target->target_id !== null ? (int) $target->target_id : null;

        return match ($type) {
            'all' => true,
            'all_customers' => $context['customerId'] !== null,
            'all_employees' => $context['employeeId'] !== null,
            'all_suppliers' => $context['supplierId'] !== null,
            'customer' => $context['customerId'] !== null && $id === (int) $context['customerId'],
            'employee' => $context['employeeId'] !== null && $id === (int) $context['employeeId'],
            'supplier' => $context['supplierId'] !== null && $id === (int) $context['supplierId'],
            'department' => $context['departmentId'] !== null && $id === (int) $context['departmentId'],
            'item' => $context['itemId'] !== null && $id === (int) $context['itemId'],
            'branch' => $context['branchId'] !== null && $id === (int) $context['branchId'],
            'category' => $context['categoryId'] !== null && $id === (int) $context['categoryId'],
            'brand' => $context['brandId'] !== null && $id === (int) $context['brandId'],
            'modifier' => $context['modifierId'] !== null && $id === (int) $context['modifierId'],
            default => false,
        };
    }

    protected function applyPriority(Collection $applicable): Collection
    {
        if ($applicable->isEmpty()) {
            return $applicable;
        }

        $allowCompound = DiscountSetting::get('allow_compound_discounts', 'true') === 'true';

        $sorted = $applicable->sortBy(function ($item) {
            return match ($this->priorityMode) {
                'lowest_first' => [$item['discount']->priority, $item['discount_amount']],
                'cumulative' => $item['discount']->priority,
                default => [$item['discount']->priority, -$item['discount_amount']],
            };
        })->values();

        return $allowCompound ? $sorted : collect([$sorted->first()]);
    }

    protected function ruleSummary(Discount $discount, string $reason): array
    {
        return [
            'id' => $discount->id,
            'name' => $discount->name,
            'code' => $discount->code,
            'priority' => $discount->priority,
            'apply_strategy' => $discount->apply_strategy ?? 'per_quantity',
            'reason' => $reason,
        ];
    }

    public function recalculateCartWithDiscounts(array $cartItems, ?int $customerId = null): array
    {
        return $this->calculateCartDiscounts($cartItems, $customerId)['items'];
    }
}
