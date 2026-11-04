<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\InventorySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إعدادات المخزون القابلة للتعديل (قسم "المخزون" في الإعدادات العامة). */
class InventorySettingController extends InventoryController
{
    /** GET /api/inventory/settings */
    public function show(): JsonResponse
    {
        return $this->success('تم', $this->payload(InventorySetting::current()));
    }

    /** PUT /api/inventory/settings {allow_negative_stock?, variance_alert_percent?} */
    public function update(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'variance_alert_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'expiry_warning_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'expiry_critical_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'showroom_max_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'spice_grams_per_kg' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'price_alert_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
        ]);

        return $this->success('تم حفظ إعدادات المخزون', $this->payload(InventorySetting::set($data, $request->user()->id)));
    }

    /** @return array<string, mixed> */
    private function payload(InventorySetting $s): array
    {
        return [
            'allow_negative_stock' => $s->allow_negative_stock,
            'variance_alert_percent' => (string) $s->variance_alert_percent,
            'expiry_warning_days' => $s->expiry_warning_days,
            'expiry_critical_days' => $s->expiry_critical_days,
            'showroom_max_days' => $s->showroom_max_days,
            'spice_grams_per_kg' => (string) $s->spice_grams_per_kg,
            'price_alert_percent' => (string) $s->price_alert_percent,
            'business_timezone' => config('inventory.business_timezone'),
            'accounting_day_start' => config('inventory.accounting_day_start'),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
