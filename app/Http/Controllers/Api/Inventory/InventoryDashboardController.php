<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockMovement;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/inventory/dashboard — الصفحة الرئيسية للوحة المخازن.
 *
 * كل الأرقام ضمن المخازن التي يصل إليها المستخدم (WarehouseAccess). "تحت مخزون الأمان" على
 * مجموع رصيد الصنف في تلك المخازن مقابل safety_stock (> 0 فقط). القيمة = Σ الرصيد × متوسط
 * التكلفة، وتُرجع null لمن لا يملك inventory.costs.view.
 */
class InventoryDashboardController extends InventoryController
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user);
        $scope = fn ($q, string $column) => $ids === null ? $q : $q->whereIn($column, $ids);
        $showCosts = CostVisibility::canView($user);

        $balances = $scope(DB::table('stock_balances as b'), 'b.warehouse_id')
            ->selectRaw('b.stock_item_id, SUM(b.quantity) as quantity')
            ->groupBy('b.stock_item_id');

        $below = DB::table('stock_items as i')
            ->join('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->leftJoinSub($balances, 'bal', 'bal.stock_item_id', '=', 'i.id')
            ->where('i.is_active', true)
            ->where('i.safety_stock', '>', 0)
            ->whereRaw('COALESCE(bal.quantity, 0) < i.safety_stock');

        $movements = $scope(DB::table('stock_movements as m'), 'm.warehouse_id')
            ->join('stock_items as i', 'i.id', '=', 'm.stock_item_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->orderByDesc('m.id')
            ->limit(10)
            ->get(['m.id', 'm.type', 'm.occurred_at', 'm.quantity', 'm.total_cost', 'i.code', 'i.name as item_name', 'u.name as base_unit', 'w.name as warehouse_name'])
            ->map(function ($row) use ($showCosts) {
                $row->type_label = StockMovement::typeLabel($row->type);
                if (! $showCosts) {
                    $row->total_cost = null;
                }

                return $row;
            });

        $value = $showCosts
            ? (string) round((float) $scope(DB::table('stock_balances as b'), 'b.warehouse_id')->sum(DB::raw('b.quantity * b.avg_cost')), 2)
            : null;

        return $this->success('تم', [
            'items_count' => DB::table('stock_items')->where('is_active', true)->count(),
            'warehouses_count' => $scope(DB::table('warehouses')->where('is_active', true), 'id')->count(),
            'below_safety_count' => (clone $below)->count(),
            'below_safety' => $below
                ->orderByRaw('COALESCE(bal.quantity, 0) / i.safety_stock')
                ->limit(10)
                ->get(['i.id', 'i.code', 'i.name', 'u.name as base_unit', 'i.safety_stock', DB::raw('COALESCE(bal.quantity, 0) as quantity')]),
            'recent_movements' => $movements,
            'stock_value' => $value,
        ]);
    }
}
