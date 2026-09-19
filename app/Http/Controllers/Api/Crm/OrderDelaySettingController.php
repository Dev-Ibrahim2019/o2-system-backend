<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmOrderDelaySetting;
use App\Services\Crm\CrmOrderDelayAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company-wide "after how many minutes is an active order considered
 * delayed" setting — the same number the الطلبات المتأخرة screen's own
 * ?minutes= filter has always expressed ad hoc, now persisted so the
 * scheduled crm:orders:check-delays job (see CrmOrderDelayAlertService) has
 * one durable source of truth to alert against, independent of any one
 * user's open tab.
 */
class OrderDelaySettingController extends Controller
{
    public function __construct(private readonly CrmOrderDelayAlertService $alerts)
    {
    }

    /** GET /api/crm/orders/delay-settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['threshold_minutes' => CrmOrderDelaySetting::current()]]);
    }

    /**
     * PUT /api/crm/orders/delay-settings
     *
     * Re-runs the delay check synchronously right after saving — a manager
     * lowering the threshold from 30 to 5 expects the orders already past 5
     * minutes to alert now, not on the next scheduled minute tick.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'threshold_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $minutes = (int) $data['threshold_minutes'];
        CrmOrderDelaySetting::set($minutes, $request->user()->id);
        $this->alerts->checkAndNotify();

        return response()->json(['data' => ['threshold_minutes' => $minutes]]);
    }
}
