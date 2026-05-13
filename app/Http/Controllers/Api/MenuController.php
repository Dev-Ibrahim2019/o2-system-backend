<?php
// app/Http/Controllers/Api/MenuController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Item;

class MenuController extends ApiController
{
    // GET /menu?branch_id=1
    public function index()
    {
        $branchId = request('branch_id');

        $query = Item::with('department')
            ->where('items.is_active', true); // ✅ تحديد الجدول

        if ($branchId) {
            // ✅ الإصلاح: نجلب الـ pivot مع withPivot صريح
            $query
                ->whereHas(
                    'branches',
                    fn($q) =>
                    $q->where('branch_item.branch_id', $branchId) // ✅ الأفضل
                        ->where('branches.is_active', true)         // ✅ تحديد الجدول
                )
                ->with([
                    'branches' => fn($q) =>
                    $q->where('branch_item.branch_id', $branchId) // ✅
                        ->where('branches.is_active', true)         // (اختياري لكن أفضل)
                        ->withPivot(['price', 'is_active'])
                ]);
        }

        $items = $query->get();

        $categories = $items
            ->groupBy('department_id')
            ->map(function ($deptItems) use ($branchId) {
                $dept = $deptItems->first()->department;

                return [
                    'id'      => $dept->id,
                    'name'    => $dept->name,
                    'name_ar' => $dept->name,
                    'icon'    => $dept->icon   ?? '🍽️',
                    'color'   => $dept->color  ?? '#ef4444',
                    'type'    => $dept->type,

                    'items' => $deptItems->map(fn($item) => [
                        'id'            => $item->id,
                        'name'          => $item->name,
                        'name_ar'       => $item->name_ar ?? $item->name,
                        'code'          => $item->code,
                        'image'         => $item->image,
                        'unit'          => $item->unit,
                        // ✅ الإصلاح: نقرأ الـ price من pivot بشكل صحيح
                        'price'         => $branchId
                            ? (float) optional($item->branches->first()?->pivot)->price
                            : 0,
                        'department_id' => $item->department_id,
                    ])->values(),
                ];
            })
            ->values();

        return $this->success('Menu fetched', [
            'categories'  => $categories,
            'total_items' => $items->count(),
        ]);
    }
}