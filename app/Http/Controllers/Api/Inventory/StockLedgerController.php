<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockCount;
use App\Models\StockDocument;
use App\Models\StockDocumentLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\SaleConsumptionService;
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

    /** GET /api/inventory/balances?warehouse_id=&stock_item_id=&search=&category_id=&nonzero=1&per_page= */
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
            ->when($request->filled('stock_item_id'), fn ($q) => $q->where('b.stock_item_id', $request->integer('stock_item_id')))
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
     * GET /api/inventory/kardex?stock_item_id=&warehouse_id=&from=&to=&page=  (كرت الصنف)
     *
     * المخزن اختياري: بدونه تُجمع حركات الصنف في كل مخازن المستخدم بترتيب التسجيل. الفترة بالأيام المحاسبية
     * (from/to شاملان؛ الافتراضي آخر 30 يوماً). الرصيد الجاري لكل سطر:
     *   رصيد أول الفترة = مجموع كميات الحركات قبل يوم البداية (في المخازن المختارة)
     *   الرصيد الجاري  = رصيد أول الفترة + مجموع كميات حركات الفترة حتى هذا السطر (بترتيب التسجيل)
     * وكذلك القيمة الجارية بمجموع قيم الحركات. الحساب من الحركات نفسها لا من لقطة balance_after،
     * فيبقى صحيحاً حتى لو سُجّلت حركة بتاريخ سابق (استيراد تاريخي).
     * لكل سطر: المخزن، رقم المستند الأصلي ورابطه، والحساب المقابل (المورّد، المخزن الآخر في الصرفية...).
     */
    public function kardex(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        if ($warehouseId && ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($warehouseId)))) {
            return $denied;
        }
        $user = $request->user();
        $scope = $warehouseId ? [$warehouseId] : ($this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user));

        $to = $data['to'] ?? AccountingDay::fromConfig()->of(now());
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(30)->toDateString();
        $perPage = min(max($request->integer('per_page', 50), 1), 200);

        $base = fn () => StockMovement::query()
            ->where('stock_item_id', $data['stock_item_id'])
            ->when($scope !== null, fn ($q) => $q->whereIn('warehouse_id', $scope));
        $inRange = fn () => $base()->whereBetween('accounting_day', [$from, $to]);
        $sums = 'COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(total_cost), 0) as value';

        $opening = $base()->where('accounting_day', '<', $from)->selectRaw($sums)->toBase()->first();

        $page = $inRange()
            ->with(['enteredUnit:id,name', 'creator:id,name', 'reversedBy:id,reversal_of_id', 'warehouse:id,name,code'])
            ->orderBy('id')
            ->paginate($perPage);

        // ما سبق أول سطر في هذه الصفحة داخل الفترة نفسها.
        $firstId = $page->getCollection()->first()?->id;
        $carry = $firstId
            ? $inRange()->where('id', '<', $firstId)->selectRaw($sums)->toBase()->first()
            : (object) ['qty' => '0', 'value' => '0'];

        $runningQty = bcadd((string) $opening->qty, (string) $carry->qty, 6);
        $runningValue = bcadd((string) $opening->value, (string) $carry->value, 6);
        $sources = $this->movementSources($page->getCollection());

        $rows = $page->getCollection()->map(function (StockMovement $m) use (&$runningQty, &$runningValue, $sources) {
            $runningQty = bcadd($runningQty, (string) $m->quantity, 6);
            $runningValue = bcadd($runningValue, (string) $m->total_cost, 6);
            $incoming = bccomp((string) $m->quantity, '0', 6) > 0;
            $source = $sources($m);

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
                'warehouse' => $m->warehouse ? ['id' => $m->warehouse->id, 'name' => $m->warehouse->name, 'code' => $m->warehouse->code] : null,
                'quantity' => $m->quantity,
                'quantity_in' => $incoming ? (string) $m->quantity : '0',
                'quantity_out' => $incoming ? '0' : bcmul((string) $m->quantity, '-1', 6),
                'unit_cost' => $m->unit_cost,
                'total_cost' => $m->total_cost,
                'avg_cost_after' => $m->avg_cost_after,
                'running_quantity' => $runningQty,
                'running_value' => $runningValue,
                'entered_quantity' => $m->entered_quantity,
                'entered_unit' => $m->enteredUnit?->name,
                'notes' => $m->notes,
                'description' => $m->notes ?: StockMovement::typeLabel($m->type),
                'created_by' => $m->creator?->name,
                'source' => $source['source'],
                'number' => $source['number'],
                'account_name' => $source['account'],
            ];
        });

        $totals = $inRange()
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) as qty_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) as qty_out')
            ->selectRaw('COALESCE(SUM(quantity), 0) as net, COALESCE(SUM(total_cost), 0) as value')
            ->toBase()
            ->first();

        $item = StockItem::with('baseUnit:id,name')->findOrFail($data['stock_item_id'], ['id', 'code', 'name', 'base_unit_id']);

        $showCosts = CostVisibility::canView($user);
        if (! $showCosts) {
            $rows = $rows->map(fn ($r) => CostVisibility::strip($r, ['unit_cost', 'total_cost', 'avg_cost_after', 'running_value']));
        }

        return $this->success('تم', [
            'costs_visible' => $showCosts,
            'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'base_unit' => $item->baseUnit?->name],
            'warehouse' => $warehouseId ? Warehouse::find($warehouseId, ['id', 'name', 'code']) : null,
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

    /**
     * أصل كل حركة في الصفحة (بنداءات مجمّعة): رقم المستند، ورابطه، والحساب المقابل.
     *
     * @param  \Illuminate\Support\Collection<int, StockMovement>  $movements
     * @return \Closure(StockMovement): array{source: array<string, mixed>|null, number: string|null, account: string|null}
     */
    private function movementSources($movements): \Closure
    {
        $docIds = $movements->where('source_type', StockDocument::SOURCE_TYPE)->pluck('source_id')->unique()->values();
        $docs = StockDocument::with(['supplier:id,name', 'warehouse:id,name', 'toWarehouse:id,name'])
            ->whereIn('id', $docIds)
            ->get(['id', 'number', 'type', 'supplier_id', 'warehouse_id', 'to_warehouse_id', 'purchase_bill_id'])
            ->keyBy('id');
        // سطر المستند من مفتاح منع التكرار "doc:{id}:line:{line}[:out|:in]" (وجهة السطر في الصرفية).
        $lineOf = fn (StockMovement $m) => preg_match('/^doc:\d+:line:(\d+)/', (string) $m->idempotency_key, $x) ? (int) $x[1] : null;
        $lines = StockDocumentLine::with('toWarehouse:id,name')
            ->whereIn('id', $movements->map($lineOf)->filter()->unique()->values())
            ->get(['id', 'to_warehouse_id'])
            ->keyBy('id');
        $counts = StockCount::whereIn('id', $movements->where('source_type', StockCount::SOURCE_TYPE)->pluck('source_id')->unique())
            ->pluck('number', 'id');
        // خصم البيع: رقم الطلب المدفوع الذي استهلك المادة حسب الوصفة.
        $orders = DB::table('orders')->whereIn('id', $movements->where('source_type', SaleConsumptionService::SOURCE_TYPE)->pluck('source_id')->unique())
            ->pluck('order_number', 'id');

        return function (StockMovement $m) use ($docs, $lines, $lineOf, $counts, $orders): array {
            if ($m->source_type === SaleConsumptionService::SOURCE_TYPE) {
                return ['source' => null, 'number' => $orders->get($m->source_id) ?? '#'.$m->source_id, 'account' => 'تكلفة المبيعات'];
            }
            if ($m->source_type === StockDocument::SOURCE_TYPE && ($doc = $docs->get($m->source_id))) {
                $account = match ($doc->type) {
                    StockDocument::TYPE_RECEIPT => $doc->supplier?->name,
                    StockDocument::TYPE_TRANSFER => in_array($m->type, ['transfer_out'], true)
                        ? ($lines->get($lineOf($m) ?? 0)?->toWarehouse?->name ?? $doc->toWarehouse?->name)
                        : $doc->warehouse?->name,
                    default => 'مصروف الهالك',
                };

                return [
                    'source' => ['kind' => 'document', 'type' => $doc->type, 'id' => $doc->id, 'purchase_bill_id' => $doc->purchase_bill_id],
                    'number' => $doc->number,
                    'account' => $account,
                ];
            }
            if ($m->source_type === StockCount::SOURCE_TYPE) {
                return ['source' => ['kind' => 'count', 'type' => 'count', 'id' => (int) $m->source_id], 'number' => $counts->get($m->source_id), 'account' => 'تسوية جرد'];
            }
            if ($m->type === 'opening' && $m->source_type === null) {
                return ['source' => ['kind' => 'opening', 'type' => 'opening', 'id' => (int) ($m->reversal_of_id ?? $m->id)], 'number' => '#'.$m->id, 'account' => 'رصيد أول المدة'];
            }

            return ['source' => null, 'number' => '#'.$m->id, 'account' => $m->type === 'sale' ? 'تكلفة المبيعات' : null];
        };
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
