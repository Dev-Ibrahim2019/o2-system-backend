<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockItem;
use App\Models\SupplierStockItem;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\InventoryRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * موردو الصنف المخزني وأسعارهم. بيانات المورّد نفسه (الاسم، الهاتف، شروط الدفع) تُدار من
 * شاشة الموردين في المالية؛ هنا الربط فقط.
 */
class SupplierItemController extends InventoryController
{
    /** GET /api/inventory/items/{item}/suppliers */
    public function index(Request $request, StockItem $item): JsonResponse
    {
        $showCosts = CostVisibility::canView($request->user()) || $request->user()->can('inventory.documents.manage');

        $links = $item->supplierLinks()
            ->with(['supplier:id,name,code,phone,payment_terms,status', 'unit:id,name'])
            ->orderByDesc('is_preferred')
            ->get()
            ->map(fn (SupplierStockItem $l) => $showCosts ? $l->toArray() : CostVisibility::strip($l->toArray(), ['price']));

        return $this->success('تم', $links);
    }

    /**
     * PUT /api/inventory/items/{item}/suppliers {links: [{supplier_id, unit_id?, price?, supplier_item_code?, is_preferred?}]}
     * يستبدل قائمة الموردين كاملة. مورّد مفضّل واحد على الأكثر.
     */
    public function sync(Request $request, StockItem $item): JsonResponse
    {
        $data = $this->validateAr($request, [
            'links' => ['present', 'array', 'max:50'],
            'links.*.supplier_id' => ['required', 'integer', 'distinct', 'exists:suppliers,id'],
            'links.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'links.*.price' => ['nullable', 'numeric', 'min:0'],
            'links.*.supplier_item_code' => ['nullable', 'string', 'max:64'],
            'links.*.is_preferred' => ['sometimes', 'boolean'],
        ]);

        return $this->attempt(function () use ($item, $data, $request) {
            $links = $data['links'];
            if (count(array_filter($links, fn ($l) => ! empty($l['is_preferred']))) > 1) {
                throw new InventoryRuleException('يمكن تحديد مورّد مفضّل واحد فقط للصنف.');
            }
            $item->load('units');
            foreach ($links as $l) {
                $unitId = (int) ($l['unit_id'] ?? 0);
                if ($unitId && $unitId !== (int) $item->base_unit_id && ! $item->units->contains('unit_id', $unitId)) {
                    throw new InventoryRuleException('وحدة سعر المورّد غير معرّفة للصنف.');
                }
            }

            DB::transaction(function () use ($item, $links) {
                $keep = array_map(fn ($l) => (int) $l['supplier_id'], $links);
                $item->supplierLinks()->whereNotIn('supplier_id', $keep)->get()->each->delete();
                foreach ($links as $l) {
                    SupplierStockItem::updateOrCreate(
                        ['supplier_id' => (int) $l['supplier_id'], 'stock_item_id' => $item->id],
                        [
                            'unit_id' => $l['unit_id'] ?? null,
                            'price' => $l['price'] ?? null,
                            'supplier_item_code' => $l['supplier_item_code'] ?? null,
                            'is_preferred' => (bool) ($l['is_preferred'] ?? false),
                        ],
                    );
                }
            });

            return $this->index($request, $item);
        });
    }
}
