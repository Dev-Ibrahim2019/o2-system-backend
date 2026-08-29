<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Services\Discount\DiscountEngineService;
use App\Services\Order\OrderPricingService;
use InvalidArgumentException;

/**
 * Creates invoices from orders with unified engine + manual discount reconciliation.
 */
class InvoiceFromOrderService
{
    public function __construct(
        private readonly DiscountEngineService $discountEngine,
        private readonly OrderPricingService $orderPricing,
    ) {}

    /**
     * @param  array{customer_id?: int|null, employee_id?: int|null, supplier_id?: int|null, notes?: string|null}  $data
     */
    public function createFromOrder(Order $order, array $data, ?int $appliedBy = null): Invoice
    {
        $orderItems = $order->items()->where('status', '!=', 'cancelled')->get();

        if ($orderItems->isEmpty()) {
            throw new InvalidArgumentException('لا توجد أصناف صالحة للفوترة.');
        }

        $customerId = $data['customer_id'] ?? $order->customer_id;
        $employeeId = $data['employee_id'] ?? $order->employee_id;
        $supplierId = $data['supplier_id'] ?? $order->supplier_id;
        $branchId = $order->branch_id;

        // هاي الحقول كانت توصل بـ $data من الكنترولر وتنرمى — Invoice::create()
        // تحت ما كانت تستخدمها إطلاقاً، يعني pos_register_id/opened_by/currency
        // وغيرها كانت تضل NULL على كل فاتورة بترجع مربوطة بنقطة بيع.
        $invoice = Invoice::create([
            'number' => Invoice::generateNumber(),
            'order_id' => $order->id,
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'status' => 'draft',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'invoice_date' => now(),
            'notes' => $data['notes'] ?? $order->note,
            'pos_register_id' => $data['pos_register_id'] ?? null,
            'pos_code' => $data['pos_code'] ?? null,
            'pos_name' => $data['pos_name'] ?? null,
            'opened_by' => $data['opened_by'] ?? $appliedBy,
            'opened_at' => $data['opened_at'] ?? now(),
            'currency' => $data['currency'] ?? ($order->currency ?? 'ILS'),
            'exchange_rate' => $data['exchange_rate'] ?? ($order->exchange_rate ?? 1),
            'account_number' => $data['account_number'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'daily_sequence' => Invoice::nextDailySequence($data['pos_register_id'] ?? null),
        ]);

        $grossSubtotal = 0.0;
        $engineDiscountTotal = 0.0;

        foreach ($orderItems as $orderItem) {
            $originalPrice = (float) $orderItem->price;
            $quantity = (int) $orderItem->quantity;
            $lineGross = $originalPrice * $quantity;
            $grossSubtotal += $lineGross;

            try {
                $bestDiscount = $this->discountEngine->getBestDiscount(
                    $originalPrice,
                    $quantity,
                    $customerId,
                    $employeeId,
                    $supplierId,
                    $orderItem->department_id,
                    $orderItem->item_id,
                    $branchId
                );
            } catch (\Throwable) {
                $bestDiscount = null;
            }

            $unitDiscount = 0.0;
            $lineDiscount = 0.0;
            $discountPercent = null;
            $discountId = null;
            $finalUnitPrice = $originalPrice;
            $discountModel = null;
            $applyStrategy = null;

            if ($bestDiscount && !empty($bestDiscount['discount'])) {
                $discountObj = $bestDiscount['discount'];
                if (is_object($discountObj) && $discountObj instanceof \App\Models\Discount) {
                    $lineDiscount = (float) $bestDiscount['discount_amount'];
                    $unitDiscount = $quantity > 0 ? $lineDiscount / $quantity : 0.0;
                    $discountPercent = $bestDiscount['discount_percent'];
                    $discountId = $discountObj->id;
                    $finalUnitPrice = $quantity > 0 ? (float) $bestDiscount['final_price'] / $quantity : $originalPrice;
                    $discountModel = $discountObj;
                    $applyStrategy = $bestDiscount['apply_strategy'] ?? $discountObj->apply_strategy;
                }
            }

            $lineFinal = $finalUnitPrice * $quantity;
            $engineDiscountTotal += $lineDiscount;

            $invoiceItem = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_id' => $orderItem->item_id,
                'item_name' => $orderItem->item_name,
                'quantity' => $quantity,
                'price' => $originalPrice,
                'total' => $lineFinal,
                'original_price' => $originalPrice,
                'discount_amount' => $unitDiscount,
                'discount_percent' => $discountPercent,
                'discount_id' => $discountId,
                'discount_apply_strategy' => $applyStrategy,
                'final_price' => $finalUnitPrice,
                'subtotal' => $lineGross,
            ]);

            if ($discountModel && $lineDiscount > 0) {
                try {
                    $entityType = match (true) {
                        $customerId !== null => 'customer',
                        $employeeId !== null => 'employee',
                        $supplierId !== null => 'supplier',
                        default => null,
                    };
                    $entityId = $customerId ?? $employeeId ?? $supplierId;

                    $this->discountEngine->logDiscountUsage(
                        $discountModel,
                        $lineGross,
                        $lineDiscount,
                        $lineFinal,
                        $discountPercent,
                        $invoice->id,
                        $invoiceItem->id,
                        $order->id,
                        $entityType,
                        $entityId,
                        $appliedBy,
                        $branchId
                    );
                } catch (\Throwable) {
                    // تجاهل أخطاء تسجيل الخصم — لا تمنع إنشاء الفاتورة
                }
            }
        }

        // Delivery fees belong to the order total, not to invoice item lines.
        // Reusing the pricing service guarantees they are included exactly once.
        $pricing = $this->orderPricing->calculate($order);
        $manualDiscount = (float) $pricing['discount_amount'];
        $totalDiscount = round((float) $pricing['engine_discount_amount'] + $manualDiscount, 3);
        $netTotal = (float) $pricing['total'];

        $invoice->update([
            'subtotal' => round($grossSubtotal, 3),
            'discount' => $totalDiscount,
            'total' => $netTotal,
        ]);

        // مزامنة مجاميع الطلب مع الفاتورة — مصدر واحد للحقيقة
        $order->update([
            'customer_id' => $customerId,
            'employee_id' => $employeeId,
            'supplier_id' => $supplierId,
            'subtotal' => round($grossSubtotal, 3),
            'engine_discount_amount' => round((float) $pricing['engine_discount_amount'], 3),
            'discount_amount' => round($manualDiscount, 3),
            'delivery_fee' => round((float) $pricing['delivery_fee'], 3),
            'total' => $netTotal,
        ]);

        return $invoice->fresh(['items.discountDetail', 'payments', 'order']);
    }
}
