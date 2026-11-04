<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use App\Services\Inventory\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الأرصدة، وسجل حركة الصنف (Kardex)، وأرصدة أول المدة، وعكس الحركات.
 * كل قراءة وكتابة مقيّدة بالمخازن المسموحة للمستخدم (WarehouseAccess).
 */
class StockLedgerController extends InventoryController
{
    public function __construct(private StockLedger $ledger, private WarehouseAccess $access) {}

    /**
     * GET /api/inventory/items/{item}/stock?warehouse_id=
     *
     * رصيد صنف واحد في مخزن واحد بنداء خفيف لسطر المستند: الرصيد ومتوسط التكلفة بالوحدة الأساسية،
     * ووحدات الصنف بمعاملاتها (لتحويل الرصيد للوحدة المختارة في الواجهة).
     */
    public function itemStock(Request $request, StockItem $item): JsonResponse
    {
        $data = $this->validateAr($request, ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $item->load(['baseUnit:id,name', 'units.unit:id,name']);
        $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('stock_item_id', $item->id)->first(['quantity', 'avg_cost']);

        return $this->success('تم', [
            'stock_item_id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'qty' => (string) ($balance->quantity ?? '0'),
            'unit' => $item->baseUnit?->name,
            'avg_cost' => CostVisibility::canView($request->user()) ? (string) ($balance->avg_cost ?? '0') : null,
            'units' => [
                ['id' => (int) $item->base_unit_id, 'name' => $item->baseUnit?->name, 'factor' => '1'],
                ...$item->units->map(fn ($u) => ['id' => (int) $u->unit_id, 'name' => $u->unit?->name, 'factor' => (string) $u->factor])->all(),
            ],
        ]);
    }

    /** GET /api/inventory/balances?warehouse_id=&search=&category_id=&nonzero=1&per_page= */
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('stock_balances as b')
            ->join('stock_items as i', 'i.id', '=', 'b.stock_item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->when(! $this->access->hasGlobalAccess($user), fn ($q) => $q->whereIn('b.warehouse_id', $this->access->assignedIds($user)))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('b.warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->whereExists(fn ($s) => $s->from('stock_category_item as ci')
                ->whereColumn('ci.stock_item_id', 'i.id')
                ->where('ci.stock_category_id', $request->integer('category_id'))))
            ->when($request->boolean('nonzero'), fn ($q) => $q->where('b.quantity', '<>', 0))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('i.name', 'like', "%{$search}%")
                ->orWhere('i.code', 'like', "%{$search}%")
                ->orWhere('i.barcode', $search)))
            ->orderBy('w.name')
            ->orderBy('i.name')
            ->select([
                'b.warehouse_id', 'w.name as warehouse_name', 'b.stock_item_id', 'i.code', 'i.name', 'i.type',
                'u.name as base_unit', 'b.quantity', 'b.avg_cost', 'i.safety_stock', 'b.updated_at',
                // قيمة الرصيد = الرصيد × متوسط التكلفة
                DB::raw('ROUND(b.quantity * b.avg_cost, 6) as value'),
                DB::raw('(b.quantity < i.safety_stock) as below_safety'),
            ]);

        $showCosts = CostVisibility::canView($user);
        $page = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $page->getCollection()->transform(function ($row) use ($showCosts) {
            $row->below_safety = (bool) $row->below_safety;
            if (! $showCosts) {
                $row->avg_cost = null;
                $row->value = null;
            }

            return $row;
        });

        return $this->success('تم', $page);
    }

    /**
     * GET /api/inventory/kardex?warehouse_id=&stock_item_id=&from=&to=&page=
     *
     * الفترة بالأيام المحاسبية (from/to شاملان؛ الافتراضي آخر 30 يوماً). الرصيد الجاري لكل سطر:
     *   رصيد أول الفترة = مجموع كميات الحركات قبل يوم البداية
     *   الرصيد الجاري  = رصيد أول الفترة + مجموع كميات حركات الفترة حتى هذا السطر (بترتيب التسجيل)
     * وكذلك القيمة الجارية بمجموع قيم الحركات. الحساب من الحركات نفسها لا من لقطة balance_after،
     * فيبقى صحيحاً حتى لو سُجّلت حركة بتاريخ سابق (استيراد تاريخي).
     */
    public function kardex(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($data['warehouse_id']))) {
            return $denied;
        }

        $to = $data['to'] ?? AccountingDay::fromConfig()->of(now());
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(30)->toDateString();
        $perPage = min(max($request->integer('per_page', 50), 1), 200);

        $base = fn () => StockMovement::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('stock_item_id', $data['stock_item_id']);
        $inRange = fn () => $base()->whereBetween('accounting_day', [$from, $to]);
        $sums = 'COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(total_cost), 0) as value';

        $opening = $base()->where('accounting_day', '<', $from)->selectRaw($sums)->toBase()->first();

        $page = $inRange()
            ->with(['enteredUnit:id,name', 'creator:id,name', 'reversedBy:id,reversal_of_id'])
            ->orderBy('id')
            ->paginate($perPage);

        // ما سبق أول سطر في هذه الصفحة داخل الفترة نفسها.
        $firstId = $page->getCollection()->first()?->id;
        $carry = $firstId
            ? $inRange()->where('id', '<', $firstId)->selectRaw($sums)->toBase()->first()
            : (object) ['qty' => '0', 'value' => '0'];

        $runningQty = bcadd((string) $opening->qty, (string) $carry->qty, 6);
        $runningValue = bcadd((string) $opening->value, (string) $carry->value, 6);

        $rows = $page->getCollection()->map(function (StockMovement $m) use (&$runningQty, &$runningValue) {
            $runningQty = bcadd($runningQty, (string) $m->quantity, 6);
            $runningValue = bcadd($runningValue, (string) $m->total_cost, 6);

            return [
                'id' => $m->id,
                'occurred_at' => $m->occurred_at?->toIso8601String(),
                'accounting_day' => $m->accounting_day?->toDateString(),
                'type' => $m->type,
                'type_label' => StockMovement::typeLabel($m->type),
                'reversal_of_id' => $m->reversal_of_id,
                'is_reversed' => $m->reversedBy !== null,
                'can_reverse' => $m->reversal_of_id === null && $m->reversedBy === null
                    && in_array($m->type, StockMovement::MANUALLY_REVERSIBLE, true),
                'quantity' => $m->quantity,
                'unit_cost' => $m->unit_cost,
                'total_cost' => $m->total_cost,
                'avg_cost_after' => $m->avg_cost_after,
                'running_quantity' => $runningQty,
                'running_value' => $runningValue,
                'entered_quantity' => $m->entered_quantity,
                'entered_unit' => $m->enteredUnit?->name,
                'notes' => $m->notes,
                'created_by' => $m->creator?->name,
            ];
        });

        $totals = $inRange()
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) as qty_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) as qty_out')
            ->selectRaw('COALESCE(SUM(quantity), 0) as net, COALESCE(SUM(total_cost), 0) as value')
            ->toBase()
            ->first();

        $item = StockItem::with('baseUnit:id,name')->findOrFail($data['stock_item_id'], ['id', 'code', 'name', 'base_unit_id']);

        $showCosts = CostVisibility::canView($request->user());
        if (! $showCosts) {
            $rows = $rows->map(fn ($r) => CostVisibility::strip($r, ['unit_cost', 'total_cost', 'avg_cost_after', 'running_value']));
        }

        return $this->success('تم', [
            'costs_visible' => $showCosts,
            'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'base_unit' => $item->baseUnit?->name],
            'warehouse' => Warehouse::find($data['warehouse_id'], ['id', 'name', 'code']),
            'from' => $from,
            'to' => $to,
            'opening' => ['quantity' => (string) $opening->qty, 'value' => $showCosts ? (string) $opening->value : null],
            'totals' => [
                'quantity_in' => (string) $totals->qty_in,
                'quantity_out' => (string) $totals->qty_out,
                // رصيد آخر الفترة = رصيد أول الفترة + صافي حركات الفترة
                'closing_quantity' => bcadd((string) $opening->qty, (string) $totals->net, 6),
                'closing_value' => $showCosts ? bcadd((string) $opening->value, (string) $totals->value, 6) : null,
            ],
            'rows' => $rows,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /** POST /api/inventory/movements/opening {warehouse_id, lines: [{stock_item_id, quantity, unit_id?, unit_cost}], notes?} */
    public function opening(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }

        $lines = array_map(fn (array $l) => new StockMovementLine(
            warehouseId: $warehouse->id,
            stockItemId: (int) $l['stock_item_id'],
            type: 'opening',
            quantity: (string) $l['quantity'],
            unitId: isset($l['unit_id']) ? (int) $l['unit_id'] : null,
            unitCost: (string) $l['unit_cost'],
            notes: $data['notes'] ?? null,
        ), $data['lines']);

        return $this->attempt(fn () => $this->success(
            'تم تسجيل أرصدة أول المدة',
            $this->ledger->postMany($lines, $request->user()),
            201,
        ));
    }

    /** POST /api/inventory/movements/{movement}/reverse {reason} */
    public function reverse(Request $request, StockMovement $movement): JsonResponse
    {
        $data = $this->validateAr($request, ['reason' => ['required', 'string', 'max:500']]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($movement->warehouse_id))) {
            return $denied;
        }
        if (! in_array($movement->type, StockMovement::MANUALLY_REVERSIBLE, true)) {
            return $this->error('هذا النوع من الحركات يُعكس من مستنده الأصلي، لا يدوياً.', 422);
        }

        return $this->attempt(fn () => $this->success(
            'تم عكس الحركة',
            $this->ledger->reverse($movement, $data['reason'], $request->user()),
            201,
        ));
    }
}
