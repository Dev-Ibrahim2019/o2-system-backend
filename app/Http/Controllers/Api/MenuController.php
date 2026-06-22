<?php
// app/Http/Controllers/Api/MenuController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Department;
use App\Models\Item;
use Illuminate\Http\Request;

class MenuController extends ApiController
{
    // GET /menu — يُفلتر حسب الفرع (من query param أو من المستخدم المسجل)
    public function index(Request $request)
    {
        $authUser = auth()->user();
        $branchId = $request->query('branch_id') ?? $authUser?->branch_id;

        // تحويل بيانات القسم لتتوافق مع توقعات Frontend
        $formatCategory = function ($dept) {
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
                        'is_active'     => $item->is_active ?? true,
                        'is_availble'   => true,
                    ];
                })->values()->all(),
            ];
        };

        // 1. super-admin أو لا يوجد فرع → كل الأقسام وكل الأصناف
        if (($authUser && $authUser->hasRole('super-admin')) || !$branchId) {
            $departments = Department::with(['items' => function ($query) {
                $query->where('is_active', true)->with('branches');
            }])->get()->each(function ($dept) {
                // جلب السعر من pivot لكل صنف
                $dept->items->each(function ($item) {
                    $item->price = $item->branches->isNotEmpty()
                        ? (float) ($item->branches->first()->pivot->price ?? 0)
                        : 0;
                });
            });

            return response()->json([
                'data' => [
                    'categories' => $departments->map($formatCategory)->values()->all()
                ]
            ]);
        }

        // 2. للكاشير: الأقسام المرتبطة بفرعه عبر branch_department
        // ثم بداخلها الأصناف المرتبطة بفرعه عبر branch_item
        $departments = Department::whereHas('branches', function ($q) use ($branchId) {
            $q->where('branch_department.branch_id', $branchId)
              ->where('branch_department.is_active', true);
        })
        ->with(['items' => function ($query) {
            $query->where('is_active', true)->with('branches');
        }])
        ->get()
        ->map(function ($department) use ($branchId) {
            // تعديل أسعار الأصناف حسب سعر الفرع
            $department->items = $department->items->map(function ($item) use ($branchId) {
                // البحث عن الفرع المحدد في مصفوفة الفروع
                $branch = $item->branches->firstWhere('id', $branchId);

                // إزالة الأصناف غير المفعلة في هذا الفرع
                if (!$branch || !($branch->pivot->is_active ?? true)) {
                    return null;
                }

                $item->price = $branch ? (float) ($branch->pivot->price ?? 0) : 0;
                unset($item->branches);
                return $item;
            })->filter();
            return $department;
        });

        return response()->json([
            'data' => [
                'categories' => $departments->map($formatCategory)->values()->all()
            ]
        ]);
    }
}
