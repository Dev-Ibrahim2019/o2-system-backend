<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Services\Discount\DiscountEngineService;

/**
 * Single source of truth for order monetary totals (gross, engine discount, manual discount, net).
 */
class OrderPricingService
{
    public function __construct(
        private readonly DiscountEngineService $discountEngine,
    ) {}

    /**
     * @return array{subtotal: float, engine_discount_amount: float, discount_amount: float, total: float}
     */
    public function calculate(Order $order): array
    {
        $items = $order->items()->where('status', '!=', 'cancelled')->get();

        $grossSubtotal = 0.0;
        $engineDiscountTotal = 0.0;

        foreach ($items as $item) {
            $unitPrice = (float) $item->price;
            $quantity = (int) ceil((float) $item->quantity);
            $lineGross = $unitPrice * $quantity;
            $grossSubtotal += $lineGross;

            $best = $this->discountEngine->getBestDiscount(
                $unitPrice,
                $quantity,
                $order->customer_id,
                $order->employee_id,
                $order->supplier_id,
                $item->department_id,
                $item->item_id,
                $order->branch_id
            );

            if ($best && $best['discount']) {
                $engineDiscountTotal += (float) $best['discount_amount'];
            }
        }

        $afterEngine = max(0, $grossSubtotal - $engineDiscountTotal);

        $manualDiscount = $order->discount_type === 'percent'
            ? ($afterEngine * (float) $order->discount_value / 100)
            : (float) $order->discount_value;

        $manualDiscount = max(0, round($manualDiscount, 3));
        $engineDiscountTotal = round($engineDiscountTotal, 3);
        $grossSubtotal = round($grossSubtotal, 3);
        $netTotal = max(0, round($grossSubtotal - $engineDiscountTotal - $manualDiscount, 3));

        return [
            'subtotal' => $grossSubtotal,
            'engine_discount_amount' => $engineDiscountTotal,
            'discount_amount' => $manualDiscount,
            'total' => $netTotal,
        ];
    }

    public function recalculateAndSave(Order $order): Order
    {
        $totals = $this->calculate($order);

        $order->update($totals);

        return $order->fresh();
    }
}
