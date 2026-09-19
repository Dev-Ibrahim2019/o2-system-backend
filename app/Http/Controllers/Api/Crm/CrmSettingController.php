<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CRM settings with real, enforced backend behavior — see CrmSetting's
 * own doc comment and its migrations for what each one actually does.
 * Deliberately not a home for cosmetic toggles: every other candidate
 * setting from the original design brief (default business name, default
 * language, custom fields, tags, integrations) has no backend to back it
 * yet and is left off this controller rather than faked. The two targets
 * (monthly_revenue_target, max_cancellation_rate_pct) are null until an
 * admin sets them — CrmReportController reads them to decide "on target"
 * and to raise a cancellation-rate alert, and never invents a default.
 */
class CrmSettingController extends Controller
{
    private function shape(CrmSetting $setting): array
    {
        return [
            'enabled' => $setting->enabled,
            'auto_register_pos_customers' => $setting->auto_register_pos_customers,
            'monthly_revenue_target' => $setting->monthly_revenue_target,
            'max_cancellation_rate_pct' => $setting->max_cancellation_rate_pct,
        ];
    }

    /** GET /api/crm/settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->shape(CrmSetting::current())]);
    }

    /** PUT /api/crm/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'auto_register_pos_customers' => ['sometimes', 'boolean'],
            'monthly_revenue_target' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cancellation_rate_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $setting = CrmSetting::set($data, $request->user()->id);

        return response()->json(['data' => $this->shape($setting)]);
    }
}
