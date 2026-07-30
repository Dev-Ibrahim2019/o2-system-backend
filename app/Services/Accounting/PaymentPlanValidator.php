<?php

namespace App\Services\Accounting;

use App\Models\PaymentMethod;
use RuntimeException;

class PaymentPlanValidator
{
    public function validate(float $invoiceTotal, array $payments): array
    {
        $paymentTotal = round(collect($payments)->sum('amount'), 3);
        if (abs($paymentTotal - $invoiceTotal) > 0.01) {
            throw new RuntimeException("مجموع الدفعات ({$paymentTotal}) لا يساوي إجمالي الفاتورة ({$invoiceTotal}).");
        }

        return collect($payments)->map(function (array $row) {
            $method = PaymentMethod::findOrFail($row['payment_method_id']);
            $entityType = $row['entity_type'] ?? null;
            $entityId = $row['entity_id'] ?? null;
            if ($method->is_entity && (! $entityType || ! $entityId)) {
                throw new RuntimeException("طريقة الدفع «{$method->name}» تتطلب تحديد الكيان.");
            }

            return [
                ...$row,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'subledger_type' => $entityType,
                'subledger_id' => $entityId,
            ];
        })->all();
    }
}
