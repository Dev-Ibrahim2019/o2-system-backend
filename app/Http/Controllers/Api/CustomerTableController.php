<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Department;
use App\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerTableController extends ApiController
{
    /**
     * جلب بيانات الطاولة والمنيو عبر QR Code
     * GET /api/customer/table/{qrCode}
     *
     * عام بدون تسجيل دخول — للزبائن عند مسح QR
     */
    public function lookupByQrCode(Request $request, string $qrCode)
    {
        // 1. البحث عن الطاولة عبر qr_code
        $table = DiningTable::where('qr_code', $qrCode)
            ->with(['zone.branch'])
            ->first();

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير موجودة أو رمز QR غير صحيح',
            ], 404);
        }

        // 2. جلب الفرع من المنطقة (DiningZone)
        $branch = $table->zone?->branch;
        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'الفرع غير مرتبط بالطاولة',
            ], 404);
        }

        $branchId = $branch->id;

        // 3. جلب المنيو للفرع (نفس منطق MenuController)
        $departments = Department::whereHas('items.branches', function ($q) use ($branchId) {
            $q->where('branch_item.branch_id', $branchId)
              ->where('branch_item.is_active', true);
        })
        ->with(['items' => function ($query) use ($branchId) {
            $query->where('items.is_active', true)
                  ->whereHas('branches', function ($q) use ($branchId) {
                      $q->where('branch_item.branch_id', $branchId)
                        ->where('branch_item.is_active', true);
                  });
        }])
        ->get();

        // تعيين السعر من pivot لكل صنف
        foreach ($departments as $department) {
            foreach ($department->items as $item) {
                $price = DB::table('branch_item')
                    ->where('branch_id', $branchId)
                    ->where('item_id', $item->id)
                    ->value('price');
                $item->price = $price !== null ? (float) $price : 0;
            }
        }

        // 4. تجهيز بيانات المنيو
        $categories = $departments->map(function ($dept) {
            return [
                'id'      => $dept->id,
                'name'    => $dept->name,
                'name_ar' => $dept->nameAr ?? $dept->name,
                'icon'    => $dept->icon ?? '🍽️',
                'color'   => $dept->color ?? '#ef4444',
                'type'    => $dept->type,
                'items'   => $dept->items->map(function ($item) {
                    return [
                        'id'            => $item->id,
                        'name'          => $item->name,
                        'name_ar'       => $item->name_ar ?? $item->name,
                        'code'          => $item->code,
                        'image'         => $item->image,
                        'image_url'     => $item->image_url,
                        'unit'          => $item->unit,
                        'price'         => (float) ($item->price ?? 0),
                        'department_id' => $item->department_id,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $totalItems = collect($categories)->sum(fn($c) => count($c['items']));

        // 5. الإرجاع
        return response()->json([
            'success' => true,
            'data' => [
                'table' => [
                    'id'           => $table->id,
                    'table_number' => $table->table_number,
                    'status'       => $table->status,
                    'capacity'     => $table->capacity,
                    'hall_name'    => $table->zone?->name,
                    'hall_id'      => $table->dining_zone_id,
                    'branch_id'    => $branch->id,
                    'branch_name'  => $branch->name,
                ],
                'menu' => [
                    'categories'  => $categories,
                    'total_items' => $totalItems,
                ],
                'restaurant' => [
                    'name'         => $branch->name,
                    'tagline'      => null,
                    'currency'     => '₪',
                    'discount_rate' => 0.0,
                ],
            ],
        ]);
    }
}
