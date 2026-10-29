<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Warehouse;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\InventoryAnalyticsService;
use App\Services\Inventory\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * صفحة تحليلات المخزون. كل endpoint يعيد بيانات مجمّعة جاهزة للعرض.
 * الفلاتر المشتركة: period (today|week|month|custom) و from/to و warehouse_id و category_id و stock_item_id.
 * القيم (₪) تُخفى عمّن لا يملك inventory.costs.view وتبقى الكميات.
 */
class InventoryAnalyticsController extends InventoryController
{
    /** مفاتيح تحمل قيماً مالية وتُفرغ بلا صلاحية التكاليف (الكميات لا تُمس). */
    private const MONEY_KEYS = ['value', 'cost', 'unit_cost', 'stock_value', 'actual_value', 'theoretical_value', 'variance_value',
        'total_cost', 'cost_per_product', 'avg_cost', 'price', 'food_cost_percent', 'turnover', 'coverage_days'];

    /** مخططات كلها قيم مالية: تُفرغ كاملة بلا صلاحية التكاليف. */
    private const MONEY_SERIES = ['movement_series', 'value_trend', 'value_by_warehouse', 'value_by_category', 'top_consumption'];

    public function __construct(private InventoryAnalyticsService $analytics) {}

    /** GET /api/inventory/analytics/overview — KPIs ومخططات القيمة والحركة والتنبيهات. */
    public function overview(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($f) => $this->analytics->overview($f));
    }

    /** GET /api/inventory/analytics/variance — جدول الفعلي مقابل النظري لكل صنف. */
    public function variance(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($f) => $this->analytics->variance($f));
    }

    /** GET /api/inventory/analytics/item-series?stock_item_id= — يومي فعلي/نظري ومتوسط التكلفة. */
    public function itemSeries(Request $request): JsonResponse
    {
        $id = $this->validateAr($request, ['stock_item_id' => ['required', 'integer', 'exists:stock_items,id']])['stock_item_id'];

        return $this->respond($request, fn ($f) => $this->analytics->itemSeries($f, (int) $id));
    }

    /** GET /api/inventory/analytics/ingredient?stock_item_id= — توزيع استهلاك المكوّن على المنتجات. */
    public function ingredient(Request $request): JsonResponse
    {
        $id = $this->validateAr($request, ['stock_item_id' => ['required', 'integer', 'exists:stock_items,id']])['stock_item_id'];

        return $this->respond($request, fn ($f) => $this->analytics->ingredientBreakdown($f, (int) $id));
    }

    /** GET /api/inventory/analytics/product?item_id= — مكوّنات منتج منيو واستهلاكها وتكلفة وصفته. */
    public function product(Request $request): JsonResponse
    {
        $id = $this->validateAr($request, ['item_id' => ['required', 'integer', 'exists:items,id']])['item_id'];

        return $this->respond($request, fn ($f) => $this->analytics->productBreakdown($f, (int) $id));
    }

    /** GET /api/inventory/analytics/export?table=variance|top_consumption|low_stock|stagnant|ingredient|product&... — ملف Excel. */
    public function export(Request $request): JsonResponse|BinaryFileResponse
    {
        $data = $this->validateAr($request, [
            'table' => ['required', Rule::in(['variance', 'top_consumption', 'low_stock', 'stagnant', 'ingredient', 'product'])],
            'item_id' => ['required_if:table,product', 'nullable', 'integer', 'exists:items,id'],
        ]);
        if ($data['table'] === 'ingredient' && ! $request->filled('stock_item_id')) {
            return $this->error('اختر المكوّن أولاً.', 422);
        }
        if ($denied = $this->checkWarehouse($request)) {
            return $denied;
        }
        $f = $this->filtersFrom($request);
        $money = CostVisibility::canView($request->user());
        $m = fn ($v) => $money ? $v : null;

        [$title, $headings, $rows] = match ($data['table']) {
            'variance' => ['الفعلي مقابل النظري',
                ['الكود', 'الصنف', 'الوحدة', 'أول المدة', 'الوارد', 'تحويل وارد', 'تحويل صادر', 'آخر المدة', 'الهالك', 'تسويات الجرد', 'الفعلي', 'النظري', 'الفرق', 'الفرق ₪', 'الفرق %', 'آخر جرد'],
                array_map(fn ($r) => [$r['code'], $r['name'], $r['base_unit'], $r['opening'], $r['inflow'], $r['transfers_in'], $r['transfers_out'],
                    $r['closing'], $r['waste'], $r['adjustment'], $r['actual_qty'], $r['theoretical_qty'], $r['variance_qty'],
                    $m($r['variance_value']), $r['variance_percent'], $r['last_count_date']], $this->analytics->variance($f)['rows'])],
            'top_consumption' => ['أعلى استهلاك', ['الصنف', 'الكمية', 'الوحدة', 'القيمة ₪'],
                array_map(fn ($r) => [$r['name'], $r['quantity'], $r['base_unit'], $m($r['value'])], $this->analytics->overview($f)['top_consumption'])],
            'low_stock' => ['تحت مخزون الأمان', ['الكود', 'الصنف', 'المخزن', 'الرصيد', 'مخزون الأمان', 'الوحدة', 'الحالة', 'الكمية المقترحة', 'المورّد المفضّل'],
                array_map(fn ($r) => [$r['code'], $r['name'], $r['warehouse'], $r['quantity'], $r['safety_stock'], $r['base_unit'],
                    $r['status'] === 'below' ? 'تحت الحد' : 'قريب من الحد', $r['suggested'], $r['supplier']], $this->analytics->lowStock($f))],
            'stagnant' => ['الأصناف الراكدة', ['الكود', 'الصنف', 'المخزن', 'الرصيد', 'الوحدة', 'القيمة ₪', 'آخر حركة', 'أيام بلا حركة'],
                array_map(fn ($r) => [$r['code'], $r['name'], $r['warehouse'], $r['quantity'], $r['base_unit'], $m($r['value']),
                    $r['last_movement_day'], $r['idle_days']], $this->analytics->stagnant($f))],
            'ingredient' => ['استهلاك المكوّن', ['المنتج', 'المباع', 'لكل وحدة', 'المستهلك', 'التكلفة ₪', 'النسبة %'],
                array_map(fn ($r) => [$r['name'], $r['sold'], $r['per_unit'], $r['consumed'], $m($r['cost']), $r['share_percent']],
                    $this->analytics->ingredientBreakdown($f, $request->integer('stock_item_id'))['rows'])],
            'product' => ['مكوّنات المنتج', ['المكوّن', 'الوحدة', 'لكل وحدة', 'المستهلك', 'تكلفة الوحدة ₪', 'التكلفة ₪', 'التكلفة لكل منتج ₪'],
                array_map(fn ($r) => [$r['name'], $r['base_unit'], $r['per_unit'], $r['consumed'], $m($r['unit_cost']), $m($r['cost']), $m($r['cost_per_product'])],
                    $this->analytics->productBreakdown($f, (int) $data['item_id'])['rows'])],
        };

        $path = SimpleXlsx::write($title, $headings, $rows);

        return response()->download($path, "inventory-{$data['table']}-{$f['from']}_{$f['to']}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function respond(Request $request, callable $build): JsonResponse
    {
        if ($denied = $this->checkWarehouse($request)) {
            return $denied;
        }
        $f = $this->filtersFrom($request);
        $result = $build($f);
        $money = CostVisibility::canView($request->user());
        if (! $money) {
            foreach (self::MONEY_SERIES as $key) {
                if (array_key_exists($key, $result)) {
                    $result[$key] = [];
                }
            }
            $result = $this->stripMoney($result);
        }

        return $this->success('تم', [
            ...$result,
            'filters' => ['from' => $f['from'], 'to' => $f['to'], 'days' => $f['days'], 'threshold' => $f['threshold']],
            'costs_visible' => $money,
        ]);
    }

    /** @return array<string, mixed> */
    private function filtersFrom(Request $request): array
    {
        $data = $this->validateAr($request, [
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'stagnant_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return $this->analytics->filters($data, $request->user());
    }

    private function checkWarehouse(Request $request): ?JsonResponse
    {
        if (! $request->filled('warehouse_id')) {
            return null;
        }
        $warehouse = Warehouse::find($request->integer('warehouse_id'));

        return $warehouse ? $this->denyUnlessCanAccess($request, $warehouse) : null;
    }

    /** يُفرغ القيم المالية في أي عمق من النتيجة. */
    private function stripMoney(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY_KEYS, true)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->stripMoney($v);
            }
        }

        return $data;
    }
}
