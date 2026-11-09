<?php

namespace App\Services\Inventory;

use App\Models\InventorySetting;
use App\Models\StockCount;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * جرد القسم (تبويب "الجرد" في صفحة القسم) فوق جداول الجرد الموجودة (stock_counts على مخزن القسم).
 *
 * لكل مادة في مخزن القسم، منذ نقطة البداية (آخر جرد معتمد شملها، وإلا بداية الفترة):
 *   الافتتاحي + الصرفيات الواردة − المحوّل من القسم − استهلاك البيع − الهالك ± أخرى = المتوقع
 * و"المتوقع" = رصيد الكاردكس الآن، والافتتاحي = المتوقع − صافي حركات ما بعد نقطة البداية، فتتطابق المعادلة مع
 * كرت الصنف دائماً. "أخرى" = ما سوى ذلك (مشتريات مباشرة، رصيد أول مدة، تسويات، إنتاج).
 * العكس بنوع حركته الأصلية، فيصافيها في العمود نفسه.
 *
 * الفرق = الفعلي − المتوقع، وهو بالضبط ما ينزل تسويةً عند الاعتماد (StockCountService::post يحسبه من رصيد لحظة
 * الاعتماد). الجرد "الآن" فقط — لا جرد بتاريخ سابق.
 */
class DepartmentCountService
{
    private const S = 6;

    /** أعمدة المعادلة ← أنواع الحركات (أخرى = الباقي). */
    public const COLUMNS = [
        'transfers_in' => ['transfer_in'],
        'transfers_out' => ['transfer_out'],
        'sale' => ['sale'],
        'waste' => ['waste'],
    ];

    /** الأعمدة التي تُعرض موجبة وهي صادرة (الحركة سالبة). */
    private const OUTGOING = ['transfers_out', 'sale', 'waste'];

    public function __construct(private StockCountService $counts) {}

    /**
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, mixed>, threshold: string, count: ?array<string, mixed>}
     */
    public function preview(Warehouse $warehouse, string $from, ?StockCount $draft): array
    {
        $balances = DB::table('stock_balances as b')
            ->join('stock_items as si', 'si.id', '=', 'b.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->where('b.warehouse_id', $warehouse->id)->where('si.is_stored', true)
            ->get(['b.stock_item_id', 'b.quantity', 'b.avg_cost', 'si.code', 'si.name', 'si.is_active', 'si.last_cost',
                DB::raw('u.name as base_unit')])
            ->keyBy('stock_item_id');

        $draftLines = $draft ? $draft->lines()->get(['stock_item_id', 'counted_quantity'])->keyBy('stock_item_id') : collect();
        // مادة في المسودة بلا سطر رصيد بعد تظهر برصيد صفر.
        $missing = $draftLines->keys()->diff($balances->keys());
        if ($missing->isNotEmpty()) {
            $extra = DB::table('stock_items as si')->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')->whereIn('si.id', $missing)
                ->get(['si.id as stock_item_id', DB::raw('0 as quantity'), DB::raw('0 as avg_cost'), 'si.code', 'si.name', 'si.is_active',
                    'si.last_cost', DB::raw('u.name as base_unit')]);
            foreach ($extra as $e) {
                $balances[$e->stock_item_id] = $e;
            }
        }

        $last = $this->lastCounts($warehouse->id);
        $moves = $this->afterStart($warehouse->id, $from)
            ->groupBy('m.stock_item_id')
            ->selectRaw('m.stock_item_id, '.$this->columnSums().', SUM(m.quantity) as net')
            ->get()->keyBy('stock_item_id');

        $lines = [];
        $tot = ['expected_value' => '0', 'variance_value' => '0', 'counted_lines' => 0, 'shortage_value' => '0', 'surplus_value' => '0'];
        foreach ($balances as $id => $b) {
            $m = $moves[$id] ?? null;
            $expected = (string) $b->quantity;
            $hasDraft = $draftLines->has($id);
            // مادة موقوفة بلا رصيد ولا حركة ولا معدود لا داعي لعرضها.
            if (! $b->is_active && ! $m && bccomp($expected, '0', self::S) === 0 && ! $hasDraft) {
                continue;
            }
            $net = (string) ($m->net ?? '0');
            $avg = bccomp((string) $b->avg_cost, '0', self::S) > 0 ? (string) $b->avg_cost : (string) ($b->last_cost ?? '0');
            $counted = $hasDraft && $draftLines[$id]->counted_quantity !== null ? (string) $draftLines[$id]->counted_quantity : null;
            $row = [
                'stock_item_id' => (int) $id,
                'code' => $b->code,
                'name' => $b->name,
                'base_unit' => $b->base_unit,
                'start' => isset($last[$id])
                    ? ['kind' => 'count', 'count_id' => (int) $last[$id]->id, 'number' => $last[$id]->number, 'at' => $last[$id]->posted_at]
                    : ['kind' => 'period', 'day' => $from],
                'opening' => $this->fmt(bcsub($expected, $net, self::S)),
                'transfers_in' => $this->fmt((string) ($m->transfers_in ?? '0')),
                'transfers_out' => $this->fmt((string) ($m->transfers_out ?? '0')),
                'sale' => $this->fmt((string) ($m->sale ?? '0')),
                'waste' => $this->fmt((string) ($m->waste ?? '0')),
                'other' => $this->fmt((string) ($m->other ?? '0')),
                'expected' => $this->fmt($expected),
                'unit_cost' => $this->fmt($avg),
                'expected_value' => $this->fmt(bcmul($expected, $avg, self::S)),
                'counted' => $counted === null ? null : $this->fmt($counted),
                ...$this->variance($counted, $expected, $avg),
            ];
            $tot['expected_value'] = bcadd($tot['expected_value'], bcmul($expected, $avg, self::S), self::S);
            if ($counted !== null) {
                $tot['counted_lines']++;
                $tot['variance_value'] = bcadd($tot['variance_value'], $row['variance_value'], self::S);
                $side = bccomp($row['variance_value'], '0', self::S) < 0 ? 'shortage_value' : 'surplus_value';
                $tot[$side] = bcadd($tot[$side], $row['variance_value'], self::S);
            }
            $lines[] = $row;
        }
        usort($lines, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        return [
            'lines' => $lines,
            'totals' => [
                'lines' => count($lines),
                'counted_lines' => $tot['counted_lines'],
                'expected_value' => $this->fmt($tot['expected_value']),
                'variance_value' => $this->fmt($tot['variance_value']),
                'shortage_value' => $this->fmt($tot['shortage_value']),
                'surplus_value' => $this->fmt($tot['surplus_value']),
            ],
            'threshold' => (string) (InventorySetting::current()->variance_alert_percent ?? '3'),
            'count' => $draft ? $this->countInfo($draft) : null,
        ];
    }

    /**
     * حركات عمود واحد من المعادلة لمادة واحدة (منذ نقطة بدايتها) — للتفصيل عند الضغط على الرقم.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: string, truncated: bool}
     */
    public function movements(Warehouse $warehouse, int $stockItemId, string $column, string $from): array
    {
        $q = $this->afterStart($warehouse->id, $from)->where('m.stock_item_id', $stockItemId);
        $known = array_merge(...array_values(self::COLUMNS));
        $column === 'other' ? $q->whereNotIn('m.type', $known) : $q->whereIn('m.type', self::COLUMNS[$column]);
        $sign = in_array($column, self::OUTGOING, true) ? '-1' : '1';

        $total = (string) (clone $q)->sum('m.quantity');
        $limit = 500;
        $rows = $q->leftJoin('stock_documents as d', fn ($j) => $j->on('d.id', '=', 'm.source_id')->where('m.source_type', StockDocument::SOURCE_TYPE))
            ->leftJoin('orders as o', fn ($j) => $j->on('o.id', '=', 'm.source_id')->where('m.source_type', SaleConsumptionService::SOURCE_TYPE))
            ->leftJoin('stock_counts as c', fn ($j) => $j->on('c.id', '=', 'm.source_id')->where('m.source_type', StockCount::SOURCE_TYPE))
            ->orderBy('m.id')
            ->limit($limit + 1)
            ->get(['m.id', 'm.type', 'm.quantity', 'm.unit_cost', 'm.total_cost', 'm.occurred_at', 'm.accounting_day', 'm.reversal_of_id',
                'm.source_type', 'm.source_id', 'm.notes', 'd.number as doc_number', 'd.type as doc_type', 'o.order_number', 'c.number as count_number']);

        return [
            'rows' => $rows->take($limit)->map(fn ($r) => [
                'id' => (int) $r->id,
                'type' => $r->type,
                'type_label' => StockMovement::typeLabel($r->type).($r->reversal_of_id ? ' (عكس)' : ''),
                'is_reversal' => $r->reversal_of_id !== null,
                'number' => $r->doc_number ?? $r->order_number ?? $r->count_number
                    ?? ($r->type === 'opening' ? 'OPN-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT) : null),
                'source' => match (true) {
                    $r->doc_number !== null => ['kind' => 'document', 'type' => $r->doc_type, 'id' => (int) $r->source_id],
                    $r->count_number !== null => ['kind' => 'count', 'id' => (int) $r->source_id],
                    $r->order_number !== null => ['kind' => 'order', 'id' => (int) $r->source_id],
                    default => null,
                },
                'occurred_at' => $r->occurred_at,
                'accounting_day' => $r->accounting_day,
                'quantity' => $this->fmt(bcmul((string) $r->quantity, $sign, self::S)),
                'unit_cost' => $this->fmt((string) $r->unit_cost),
                'total_cost' => $this->fmt(bcmul((string) $r->total_cost, $sign, self::S)),
                'notes' => $r->notes,
            ])->values()->all(),
            'total' => $this->fmt(bcmul($total, $sign, self::S)),
            'truncated' => $rows->count() > $limit,
        ];
    }

    /**
     * حفظ جرد القسم مسودةً: ورقة جديدة بمواد مخزن القسم، أو المسودة المفتوحة نفسها (تُضاف لها المواد الجديدة).
     * الكميات بالوحدة الأساسية؛ الفارغ = لم يُعدّ.
     *
     * @param  array<int, array{stock_item_id:int, quantity:mixed}>  $lines
     */
    public function saveDraft(Warehouse $warehouse, ?StockCount $draft, array $lines, ?string $notes, string $from, User $by): StockCount
    {
        if ($draft && ((int) $draft->warehouse_id !== $warehouse->id || $draft->status !== 'draft')) {
            throw new InventoryRuleException('هذه الورقة ليست مسودة جرد لهذا القسم.');
        }
        $ids = collect($lines)->pluck('stock_item_id')->map(fn ($id) => (int) $id);
        if ($draft) {
            $this->counts->addLines($draft, $ids->all());
        } else {
            $all = collect($this->preview($warehouse, $from, null)['lines'])->pluck('stock_item_id')->merge($ids)->unique()->values();
            $draft = $this->counts->createFor($warehouse, $all, $notes, $by);
        }
        $units = DB::table('stock_items')->whereIn('id', $ids)->pluck('base_unit_id', 'id');

        return $this->counts->saveCounts($draft, array_map(fn ($l) => [
            'stock_item_id' => (int) $l['stock_item_id'],
            'unit_id' => (int) ($units[(int) $l['stock_item_id']] ?? 0) ?: null,
            'entered_quantity' => $l['quantity'] ?? null,
        ], $lines), $notes);
    }

    /** @return array<int, array<string, mixed>> سجل جرود مخزن القسم (المسودات ثم الأحدث). */
    public function history(Warehouse $warehouse): array
    {
        return StockCount::where('warehouse_id', $warehouse->id)
            ->with(['creator:id,name', 'poster:id,name', 'reverser:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->orderByRaw("status = 'draft' desc")->orderByDesc('id')->limit(200)->get()
            ->map(fn (StockCount $c) => [...$this->countInfo($c),
                'lines_count' => (int) $c->lines_count,
                'counted_lines_count' => (int) $c->counted_lines_count,
                'variance_value' => $c->variance_value !== null ? $this->fmt((string) $c->variance_value) : null,
            ])->all();
    }

    /**
     * جرد معتمد أو معكوس كما سُجّل لحظة اعتماده (المتوقع والمعدود والفرق وتكلفته) — للقراءة فقط.
     *
     * @return array{count: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, mixed>, threshold: string}
     */
    public function posted(StockCount $count): array
    {
        $lines = DB::table('stock_count_lines as l')
            ->join('stock_items as si', 'si.id', '=', 'l.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->where('l.stock_count_id', $count->id)->whereNotNull('l.counted_quantity')
            ->orderBy('si.code')
            ->get(['l.stock_item_id', 'si.code', 'si.name', DB::raw('u.name as base_unit'),
                'l.expected_quantity', 'l.counted_quantity', 'l.variance_quantity', 'l.unit_cost', 'l.variance_value', 'l.movement_id']);

        $tot = ['variance_value' => '0', 'shortage_value' => '0', 'surplus_value' => '0'];
        $rows = [];
        foreach ($lines as $l) {
            $expected = (string) $l->expected_quantity;
            $varQty = $l->variance_quantity !== null ? (string) $l->variance_quantity : bcsub((string) $l->counted_quantity, $expected, self::S);
            $value = (string) ($l->variance_value ?? '0');
            $tot['variance_value'] = bcadd($tot['variance_value'], $value, self::S);
            $side = bccomp($value, '0', self::S) < 0 ? 'shortage_value' : 'surplus_value';
            $tot[$side] = bcadd($tot[$side], $value, self::S);
            $rows[] = [
                'stock_item_id' => (int) $l->stock_item_id,
                'code' => $l->code,
                'name' => $l->name,
                'base_unit' => $l->base_unit,
                'expected' => $this->fmt($expected),
                'counted' => $this->fmt((string) $l->counted_quantity),
                'variance_quantity' => $this->fmt($varQty),
                'unit_cost' => $l->unit_cost !== null ? $this->fmt((string) $l->unit_cost) : null,
                'variance_value' => $this->fmt($value),
                'variance_percent' => $this->percent($varQty, $expected),
                'movement_id' => $l->movement_id ? (int) $l->movement_id : null,
            ];
        }

        return [
            'count' => $this->countInfo($count->load(['creator:id,name', 'poster:id,name', 'reverser:id,name'])),
            'lines' => $rows,
            'totals' => [
                'counted_lines' => count($rows),
                'variance_value' => $this->fmt($tot['variance_value']),
                'shortage_value' => $this->fmt($tot['shortage_value']),
                'surplus_value' => $this->fmt($tot['surplus_value']),
            ],
            'threshold' => (string) (InventorySetting::current()->variance_alert_percent ?? '3'),
        ];
    }

    /** @return array<string, mixed> */
    public function countInfo(StockCount $c): array
    {
        return [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status,
            'notes' => $c->notes,
            'count_date' => $c->count_date?->toDateString(),
            'created_at' => $c->created_at?->toIso8601String(),
            'posted_at' => $c->posted_at?->toIso8601String(),
            'reversed_at' => $c->reversed_at?->toIso8601String(),
            'reversal_reason' => $c->reversal_reason,
            'creator' => $c->creator?->name,
            'poster' => $c->poster?->name,
            'reverser' => $c->reverser?->name,
        ];
    }

    // ── داخلي ────────────────────────────────────────────────────────────────

    /** @return Collection<int, object> آخر جرد معتمد (غير معكوس) عُدّت فيه كل مادة في هذا المخزن. */
    private function lastCounts(int $warehouseId): Collection
    {
        return $this->countedLines($warehouseId)
            ->orderBy('c.posted_at')->orderBy('c.id')
            ->get(['l.stock_item_id', 'c.id', 'c.number', 'c.posted_at'])
            ->keyBy('stock_item_id');   // الأحدث يكتب فوق الأقدم
    }

    private function countedLines(int $warehouseId): Builder
    {
        return DB::table('stock_count_lines as l')
            ->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
            ->where('c.warehouse_id', $warehouseId)->where('c.status', 'posted')->whereNotNull('l.counted_quantity');
    }

    /**
     * حركات مخزن القسم بعد نقطة بداية كل مادة: بعد لحظة اعتماد آخر جرد لها (تسوياته سُجّلت في لحظة الاعتماد
     * نفسها فتدخل في الافتتاحي)، وإلا من بداية الفترة.
     */
    private function afterStart(int $warehouseId, string $from): Builder
    {
        $lastAt = $this->countedLines($warehouseId)->groupBy('l.stock_item_id')
            ->selectRaw('l.stock_item_id, MAX(c.posted_at) as at');

        return DB::table('stock_movements as m')
            ->leftJoinSub($lastAt, 'lc', 'lc.stock_item_id', '=', 'm.stock_item_id')
            ->where('m.warehouse_id', $warehouseId)
            ->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereNotNull('lc.at')->whereColumn('m.created_at', '>', 'lc.at'))
                ->orWhere(fn ($x) => $x->whereNull('lc.at')->where('m.accounting_day', '>=', $from)));
    }

    private function columnSums(): string
    {
        $parts = [];
        foreach (self::COLUMNS as $col => $types) {
            $in = "'".implode("','", $types)."'";
            $sign = in_array($col, self::OUTGOING, true) ? '-' : '';
            $parts[] = "SUM(CASE WHEN m.type IN ({$in}) THEN {$sign}m.quantity ELSE 0 END) as {$col}";
        }
        $known = "'".implode("','", array_merge(...array_values(self::COLUMNS)))."'";
        $parts[] = "SUM(CASE WHEN m.type NOT IN ({$known}) THEN m.quantity ELSE 0 END) as other";

        return implode(', ', $parts);
    }

    /** @return array{variance_quantity:?string, variance_value:?string, variance_percent:?string} */
    private function variance(?string $counted, string $expected, string $unitCost): array
    {
        if ($counted === null) {
            return ['variance_quantity' => null, 'variance_value' => null, 'variance_percent' => null];
        }
        $diff = bcsub($counted, $expected, self::S);

        return [
            'variance_quantity' => $this->fmt($diff),
            'variance_value' => $this->fmt(bcmul($diff, $unitCost, self::S)),
            'variance_percent' => $this->percent($diff, $expected),
        ];
    }

    private function percent(string $part, string $whole): ?string
    {
        if (bccomp($whole, '0', self::S) === 0) {
            return null;
        }

        return number_format((float) bcdiv(bcmul($part, '100', self::S), $whole, self::S), 2, '.', '');
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
