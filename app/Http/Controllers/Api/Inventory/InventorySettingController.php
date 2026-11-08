<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\InventorySetting;
use App\Services\Inventory\SaleConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إعدادات المخزون القابلة للتعديل (قسم "المخزون" في الإعدادات العامة). */
class InventorySettingController extends InventoryController
{
    public function __construct(private SaleConsumptionService $consumption) {}

    /** GET /api/inventory/settings */
    public function show(): JsonResponse
    {
        return $this->success('تم', $this->payload(InventorySetting::current()));
    }

    /** PUT /api/inventory/settings {allow_negative_stock?, variance_alert_percent?, price_alert_percent?, deduct_sales?} */
    public function update(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'variance_alert_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'price_alert_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'deduct_sales' => ['sometimes', 'boolean'],
        ]);
        // التشغيل يبدأ من الآن: الطلبات المدفوعة قبل التشغيل (أو أثناء الإيقاف) لا تُخصم بأثر رجعي.
        if (array_key_exists('deduct_sales', $data) && $data['deduct_sales'] && ! InventorySetting::current()->deduct_sales) {
            $data['deduct_sales_from'] = now();
        }

        return $this->success('تم حفظ إعدادات المخزون', $this->payload(InventorySetting::set($data, $request->user()->id)));
    }

    /** POST /api/inventory/sales-deduction/retry — إعادة خصم الطلبات التي فشل خصمها أو لم يُسجَّل. */
    public function retrySalesDeduction(): JsonResponse
    {
        $result = $this->consumption->retryPending();

        return $this->success(
            $result['failed'] ? "خُصم {$result['posted']} طلب، وفشل {$result['failed']}" : "خُصم {$result['posted']} طلب",
            [...$this->payload(InventorySetting::current()), 'retry' => $result],
        );
    }

    /** @return array<string, mixed> */
    private function payload(InventorySetting $s): array
    {
        return [
            'allow_negative_stock' => $s->allow_negative_stock,
            'variance_alert_percent' => (string) $s->variance_alert_percent,
            'price_alert_percent' => (string) $s->price_alert_percent,
            'deduct_sales' => (bool) $s->deduct_sales,
            'deduct_sales_from' => $s->deduct_sales_from?->toIso8601String(),
            'sales_deduction' => $this->consumption->stats(),
            'business_timezone' => config('inventory.business_timezone'),
            'accounting_day_start' => config('inventory.accounting_day_start'),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
