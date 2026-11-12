<?php

namespace App\Services\Inventory;

use App\Models\StockLayer;
use App\Models\StockLayerAllocation;
use App\Models\StockLayerRevaluation;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تقارير الدفعات (قراءة فقط): فحص المطابقة، ودفعات صنف بمراجعها، وتتبّع دفعة (من أين جاءت وأين صُرفت)،
 * وتكلفة الصرف واستهلاك المبيعات حسب الفترة.
 */
class StockLayerReportService
{
    private const S = 6;

    /** نوع الحركة الصادرة ← تسمية التقرير. */
    public const ISSUE_TYPES = [
        'sale' => 'استهلاك بيع',
        'transfer_out' => 'تحويل صادر (صرف)',
        'waste' => 'هالك',
        'adjustment' => 'تسوية جرد (نقص)',
        'purchase_return' => 'مرتجع مشتريات',
        'production_out' => 'صرف للإنتاج',
    ];

    /**
     * فحص المطابقة لكل صنف في كل مخزن: الرصيد = Σ المتبقي − Σ النقص المعلّق، وقيمة الدفتر (Σ قيم الحركات) = قيمة الدفعات.
     *
     * @param  array<int>|null  $warehouseIds
     * @return array{checked: int, mismatches: array<int, array<string, mixed>>}
     */
    public function reconcile(?array $warehouseIds = null): array
    {
        $in = $warehouseIds !== null ? ' WHERE b.warehouse_id IN ('.(implode(',', array_map('intval', $warehouseIds)) ?: '0').')' : '';
        $rows = DB::select(<<<SQL
            SELECT b.warehouse_id, w.name AS warehouse, b.stock_item_id, i.code, i.name, b.quantity,
                   COALESCE(l.qty, 0) - COALESCE(s.qty, 0) AS layer_qty,
                   COALESCE(l.val, 0) - COALESCE(s.val, 0) AS layer_value,
                   COALESCE(m.val, 0) AS ledger_value
            FROM stock_balances b
            JOIN warehouses w ON w.id = b.warehouse_id
            JOIN stock_items i ON i.id = b.stock_item_id
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(qty_remaining) qty, SUM(qty_remaining * unit_cost) val
                       FROM stock_layers WHERE is_open = 1 GROUP BY warehouse_id, stock_item_id) l
                   ON l.warehouse_id = b.warehouse_id AND l.stock_item_id = b.stock_item_id
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(qty_open) qty, SUM(qty_open * provisional_unit_cost) val
                       FROM stock_layer_shortages WHERE status = 'open' GROUP BY warehouse_id, stock_item_id) s
                   ON s.warehouse_id = b.warehouse_id AND s.stock_item_id = b.stock_item_id
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(total_cost) val FROM stock_movements GROUP BY warehouse_id, stock_item_id) m
                   ON m.warehouse_id = b.warehouse_id AND m.stock_item_id = b.stock_item_id
            {$in}
        SQL);

        $mismatches = [];
        foreach ($rows as $r) {
            $qtyDiff = bcsub((string) $r->quantity, (string) $r->layer_qty, self::S);
            // فرق التقريب المقبول في القيمة: أقل من 0.01 ₪ (كل تخصيص مقرّب لست منازل).
            $valueDiff = bcsub((string) $r->ledger_value, (string) $r->layer_value, self::S);
            if (bccomp($qtyDiff, '0', self::S) !== 0 || bccomp(ltrim($valueDiff, '-'), '0.01', self::S) > 0) {
                $mismatches[] = [
                    'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'stock_item_id' => (int) $r->stock_item_id,
                    'code' => $r->code, 'name' => $r->name, 'balance_quantity' => $this->fmt((string) $r->quantity),
                    'layer_quantity' => $this->fmt((string) $r->layer_qty), 'ledger_value' => $this->fmt((string) $r->ledger_value),
                    'layer_value' => $this->fmt((string) $r->layer_value),
                ];
            }
        }

        return ['checked' => count($rows), 'mismatches' => $mismatches];
    }

    /**
     * دفعات صنف (في مخزن أو كل المخازن): الفاتورة، المورد، التاريخ، الوارد، المتبقي، السعر، القيمة.
     *
     * @param  array<int>|null  $warehouseIds
     * @return array<int, array<string, mixed>>
     */
    public function layers(int $itemId, ?array $warehouseIds, bool $openOnly): array
    {
        return StockLayer::with(['purchaseBill:id,bill_number,bill_date', 'supplier:id,name', 'warehouse:id,name', 'origin.purchaseBill:id,bill_number'])
            ->where('stock_item_id', $itemId)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($openOnly, fn ($q) => $q->where('is_open', true))
            ->orderBy('received_at')->orderBy('id')->limit(500)->get()
            ->map(fn (StockLayer $l) => $this->presentLayer($l))->all();
    }

    /** @return array<string, mixed> */
    public function presentLayer(StockLayer $l): array
    {
        $origin = $l->origin;
        $bill = $l->purchaseBill ?? $origin?->purchaseBill;

        return [
            'id' => $l->id,
            'warehouse' => $l->warehouse ? ['id' => $l->warehouse->id, 'name' => $l->warehouse->name] : null,
            'source_type' => $l->source_type,
            'source_label' => StockLayer::SOURCE_LABELS[$l->source_type] ?? $l->source_type,
            'reference' => $this->layerReference($l),
            'purchase_bill' => $bill ? ['id' => $bill->id, 'number' => $bill->bill_number] : null,
            'supplier' => $l->supplier?->name,
            'origin_layer_id' => $l->origin_layer_id,
            'received_at' => $l->received_at?->toIso8601String(),
            'qty_in' => $this->fmt((string) $l->qty_in),
            'qty_remaining' => $this->fmt((string) $l->qty_remaining),
            'unit_cost' => $this->fmt((string) $l->unit_cost),
            'value' => $this->fmt(bcmul((string) $l->qty_remaining, (string) $l->unit_cost, self::S)),
            'is_provisional' => (bool) $l->is_provisional,
        ];
    }

    /**
     * تتبّع دفعة: مصدرها (والدفعة الأصلية عبر التحويلات)، وكل خروج منها أو عودة إليها، وفروق التكلفة، والدفعات المتفرعة.
     *
     * @return array<string, mixed>
     */
    public function trace(StockLayer $layer): array
    {
        $layer->load(['purchaseBill:id,bill_number', 'supplier:id,name', 'warehouse:id,name', 'stockItem:id,code,name,base_unit_id',
            'stockItem.baseUnit:id,name', 'origin.purchaseBill:id,bill_number', 'origin.warehouse:id,name']);
        $allocations = StockLayerAllocation::with('movement')->where('layer_id', $layer->id)->orderBy('id')->get();
        $refs = $this->movementRefs($allocations->pluck('movement')->filter());

        return [
            'layer' => [...$this->presentLayer($layer), 'item' => ['id' => $layer->stockItem?->id, 'code' => $layer->stockItem?->code,
                'name' => $layer->stockItem?->name, 'base_unit' => $layer->stockItem?->baseUnit?->name]],
            'origin' => $layer->origin ? $this->presentLayer($layer->origin) : null,
            'allocations' => $allocations->map(fn (StockLayerAllocation $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'movement_id' => $a->movement_id,
                'movement_type' => $a->movement_type,
                'type_label' => StockMovement::typeLabel($a->movement_type),
                'reference' => $refs[$a->movement_id] ?? null,
                'at' => $a->movement?->occurred_at?->toIso8601String(),
                'quantity' => $this->fmt((string) $a->quantity),
                'unit_cost' => $this->fmt((string) $a->unit_cost),
                'total_cost' => $this->fmt((string) $a->total_cost),
            ])->all(),
            'revaluations' => StockLayerRevaluation::where('layer_id', $layer->id)->orderBy('id')->get()->map(fn ($r) => [
                'reason' => $r->reason, 'quantity' => $this->fmt((string) $r->quantity), 'old_unit_cost' => $this->fmt((string) $r->old_unit_cost),
                'new_unit_cost' => $this->fmt((string) $r->new_unit_cost), 'value' => $this->fmt((string) $r->value),
                'allocation_id' => $r->allocation_id, 'at' => $r->created_at?->toIso8601String(),
            ])->all(),
            'children' => StockLayer::with('warehouse:id,name')
                ->whereIn('source_allocation_id', $allocations->pluck('id'))->orderBy('id')->get()
                ->map(fn ($c) => $this->presentLayer($c))->all(),
        ];
    }

    /**
     * تكلفة الصرف واستهلاك المبيعات حسب الفترة: لكل نوع خروج، ولكل صنف داخله — الكمية والتكلفة (شاملة فروق التسوية).
     *
     * @param  array<int>|null  $warehouseIds
     * @return array{types: array<int, array<string, mixed>>, total: string}
     */
    public function issueCosts(string $from, string $to, ?array $warehouseIds): array
    {
        $rows = DB::table('stock_movements as m')
            ->join('stock_items as i', 'i.id', '=', 'm.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereIn('m.type', array_keys(self::ISSUE_TYPES))
            ->whereBetween('m.accounting_day', [$from, $to])
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('m.warehouse_id', $warehouseIds))
            ->groupBy('m.type', 'm.stock_item_id', 'i.code', 'i.name', 'u.name')
            ->selectRaw('m.type, m.stock_item_id, i.code, i.name, u.name as base_unit, -SUM(m.quantity) as qty, -SUM(m.total_cost) as cost,
                SUM(CASE WHEN m.quantity = 0 THEN -m.total_cost ELSE 0 END) as adjustments')
            ->get()->groupBy('type');

        $types = [];
        $grand = '0';
        foreach (self::ISSUE_TYPES as $type => $label) {
            $items = $rows->get($type, collect());
            $cost = $items->reduce(fn ($c, $r) => bcadd($c, (string) $r->cost, self::S), '0');
            $grand = bcadd($grand, $cost, self::S);
            $types[] = [
                'type' => $type,
                'label' => $label,
                'cost' => $this->fmt($cost),
                'adjustments' => $this->fmt($items->reduce(fn ($c, $r) => bcadd($c, (string) $r->adjustments, self::S), '0')),
                'items' => $items->sortByDesc(fn ($r) => (float) $r->cost)->map(fn ($r) => [
                    'stock_item_id' => (int) $r->stock_item_id, 'code' => $r->code, 'name' => $r->name, 'base_unit' => $r->base_unit,
                    'quantity' => $this->fmt((string) $r->qty), 'cost' => $this->fmt((string) $r->cost),
                    'unit_cost' => bccomp((string) $r->qty, '0', self::S) === 0 ? null : $this->fmt(bcdiv((string) $r->cost, (string) $r->qty, self::S)),
                ])->values()->all(),
            ];
        }

        return ['types' => $types, 'total' => $this->fmt($grand)];
    }

    // ── داخلي ────────────────────────────────────────────────────────────────

    private function layerReference(StockLayer $l): ?string
    {
        return match ($l->source_type) {
            'purchase_invoice' => $l->purchaseBill?->bill_number,
            'receipt', 'transfer_in' => DB::table('stock_documents')->where('id', $l->source_id)->value('number'),
            'adjustment_in' => DB::table('stock_counts')->where('id', $l->source_id)->value('number'),
            'opening_balance' => $l->movement_id ? 'OPN-'.str_pad((string) $l->movement_id, 6, '0', STR_PAD_LEFT) : 'ترحيل الأرصدة',
            'split' => '#'.$l->source_id,
            default => null,
        };
    }

    /** @return array<int, string|null> رقم مستند / طلب / جرد كل حركة. */
    private function movementRefs(Collection $movements): array
    {
        $docs = DB::table('stock_documents')->whereIn('id', $movements->where('source_type', 'stock_document')->pluck('source_id'))->pluck('number', 'id');
        $orders = DB::table('orders')->whereIn('id', $movements->where('source_type', SaleConsumptionService::SOURCE_TYPE)->pluck('source_id'))->pluck('order_number', 'id');
        $counts = DB::table('stock_counts')->whereIn('id', $movements->where('source_type', 'stock_count')->pluck('source_id'))->pluck('number', 'id');
        $out = [];
        foreach ($movements as $m) {
            $out[$m->id] = match ($m->source_type) {
                'stock_document' => $docs[$m->source_id] ?? null,
                SaleConsumptionService::SOURCE_TYPE => $orders[$m->source_id] ?? null,
                'stock_count' => $counts[$m->source_id] ?? null,
                default => $m->type === 'opening' ? 'OPN-'.str_pad((string) $m->id, 6, '0', STR_PAD_LEFT) : null,
            };
        }

        return $out;
    }

    private function fmt(string $v): string
    {
        $v = bcadd($v, '0', self::S);
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }

        return $v === '-0' ? '0' : $v;
    }
}
