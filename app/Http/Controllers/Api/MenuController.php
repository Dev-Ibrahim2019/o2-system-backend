<?php
// app/Http/Controllers/Api/MenuController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Item;
use Illuminate\Support\Facades\Log;

class MenuController extends ApiController
{
    // GET /menu?branch_id=1
    public function index()
    {
        $branchId = request('branch_id');

        $query = Item::with('department')
            ->where('items.is_active', true);

        if ($branchId) {
            $query
                ->whereHas(
                    'branches',
                    fn($q) =>
                    $q->where('branch_item.branch_id', $branchId)
                        ->where('branches.is_active', true)
                )
                ->with([
                    'branches' => fn($q) =>
                    $q->where('branch_item.branch_id', $branchId)
                        ->where('branches.is_active', true)
                        ->withPivot(['price', 'is_active'])
                ]);
        }

        $items = $query->get();

        // ✅ DEBUG: سجل عدد الأصناف المسترجعة
        Log::info('[MenuController] Items fetched', [
            'branch_id' => $branchId,
            'items_count' => $items->count(),
            'items' => $items->pluck('id', 'name')->toArray(),
        ]);

        // ✅ Fallback: إذا لم يتم العثور على أصناف للفرع، نرجع كل الأصناف النشطة
        if ($branchId && $items->isEmpty()) {
            $items = Item::with('department')
                ->where('items.is_active', true)
                ->get();

            Log::info('[MenuController] Fallback triggered - all active items', [
                'items_count' => $items->count(),
                'items' => $items->pluck('id', 'name')->toArray(),
            ]);
        }

        $categories = $items
            ->groupBy('department_id')
            ->map(function ($deptItems) use ($branchId) {
                $dept = $deptItems->first()->department;

                $mappedItems = $deptItems->map(fn($item) => [
                    'id'            => $item->id,
                    'name'          => $item->name,
                    'name_ar'       => $item->name_ar ?? $item->name,
                    'code'          => $item->code,
                    'image'         => $item->image,
                    'unit'          => $item->unit,
                    'price'         => $branchId
                        ? (float) optional($item->branches->first()?->pivot)->price
                        : 0,
                    'department_id' => $item->department_id,
                ])->values();

                // ✅ DEBUG: سجل عدد الأصناف في كل قسم
                Log::info('[MenuController] Category items', [
                    'category_id' => $dept->id,
                    'category_name' => $dept->name,
                    'items_count' => $mappedItems->count(),
                    'items' => $mappedItems->pluck('id', 'name_ar')->toArray(),
                ]);

                return [
                    'id'      => $dept->id,
                    'name'    => $dept->name,
                    'name_ar' => $dept->name,
                    'icon'    => $dept->icon   ?? '🍽️',
                    'color'   => $dept->color  ?? '#ef4444',
                    'type'    => $dept->type,
                    'items'   => $mappedItems,
                ];
            })
            ->values();

        // ✅ DEBUG: سجل التوزيع النهائي
        Log::info('[MenuController] Final response', [
            'categories_count' => $categories->count(),
            'products_count' => $items->count(),
            'categories' => $categories->map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'items_count' => count($c['items']),
            ])->toArray(),
        ]);

        return $this->success('Menu fetched', [
            'categories'  => $categories,
            'total_items' => $items->count(),
        ]);
    }
}
