<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningZone;
use Illuminate\Http\Request;

class DiningZoneController extends Controller
{
    /**
     * جلب القاعات والطاولات لفرع معين
     * يُستخدم من POS والضيافة
     *
     * GET /api/dining-zones?branch_id=1
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // تحديد الفرع المطلوب
        $branchId = $request->input('branch_id');

        // إذا لم يُحدد branch_id، استخدم فرع المستخدم
        if (!$branchId) {
            $branchId = $user->branch_id;
        }

        // إذا كان المستخدم ليس super-admin، تحقق من صلاحية الوصول للفرع
        if (!$user->hasRole('super-admin') && $user->branch_id != $branchId) {
            return response()->json([
                'success' => false,
                'message' => 'لا تملك صلاحية الوصول لبيانات هذا الفرع!'
            ], 403);
        }

        $zones = DiningZone::with(['tables' => function ($query) {
            $query->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS SIGNED)");
        }])
        ->where('branch_id', $branchId)
        ->where('status', 'ACTIVE')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $zones,
        ]);
    }

    /**
     * جلب قاعة معينة مع طاولاتها
     *
     * GET /api/dining-zones/{id}
     */
    public function show($id)
    {
        $zone = DiningZone::with(['tables' => function ($query) {
            $query->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS SIGNED)");
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $zone,
        ]);
    }
}