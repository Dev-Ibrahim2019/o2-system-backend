<?php
// app/Http/Controllers/Api/MenuController.php
//
// منيو الكاشير: يُرجع الأصناف المتاحة مجمّعة حسب الأقسام
// يستخدمه الفرونت بدل constants

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Branch;
use App\Models\Item;

class MenuController extends ApiController
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /menu?branch_id=1
    //
    // يُرجع:
    // {
    //   "data": {
    //     "categories": [
    //       {
    //         "id": 1,
    //         "name": "Kitchen",
    //         "name_ar": "المطبخ",
    //         "icon": "🍳",
    //         "color": "#e74c3c",
    //         "items": [ { id, name, name_ar, price, image, ... } ]
    //       }
    //     ]
    //   }
    // }
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $branchId = request('branch_id');

        // ── جلب الأصناف المتاحة ─────────────────────────────────────────────
        $query = Item::with('department')
            ->where('is_active', true);

        // إذا مُرِّر branch_id → نأخذ فقط الأصناف المرتبطة بالفرع وسعرها الخاص
        if ($branchId) {
            $query->whereHas(
                'branches',
                fn($q) =>
                $q->where('branch_id', $branchId)->where('is_active', true)
            )->with([
                'branches' => fn($q) =>
                $q->where('branch_id', $branchId)->select('branches.id')
            ]);
        }

        $items = $query->get();

        // ── تجميع حسب القسم ─────────────────────────────────────────────────
        $categories = $items
            ->groupBy('department_id')
            ->map(function ($deptItems, $deptId) use ($branchId) {
                $dept = $deptItems->first()->department;

                return [
                    'id'      => $dept->id,
                    'name'    => $dept->name,
                    'name_ar' => $dept->name,          // يمكن إضافة name_ar للـ dept لاحقاً
                    'icon'    => $dept->icon ?? '🍽️',
                    'color'   => $dept->color ?? '#ef4444',
                    'type'    => $dept->type,

                    'items'   => $deptItems->map(fn($item) => [
                        'id'       => $item->id,
                        'name'     => $item->name,
                        'name_ar'  => $item->name_ar ?? $item->name,
                        'code'     => $item->code,
                        'image'    => $item->image,
                        'unit'     => $item->unit,
                        // السعر: من pivot إذا branch_id مُرِّر، وإلا null
                        'price'    => $branchId
                            ? optional($item->branches->first())->pivot?->price
                            : null,
                        'department_id' => $item->department_id,
                    ])->values(),
                ];
            })
            ->values();

        return $this->success('Menu fetched', [
            'categories' => $categories,
            'total_items' => $items->count(),
        ]);
    }
}
