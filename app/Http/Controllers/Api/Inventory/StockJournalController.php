<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\WarehouseAccess;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "الحركات المخزنية" (شاشة الأمين): قائمة موحدة بكل ما حرّك المخزون، مستنداً مستنداً — للقراءة فقط.
 *
 * المصادر: المستندات المرحّلة أو المعكوسة (استلام، صرفية، هالك)، والجرد المثبّت (تسوية)، وأرصدة أول المدة
 * (حركات بلا مستند تُجمَّع بالمخزن والمستخدم ولحظة التسجيل، ورقمها OPN- + أول حركة). المسودات ليست حركات.
 * الحساب = المورّد في الاستلام، والمخزن المحول له في الصرفية، والمخزن نفسه في غيرهما (المخزون لا يُنشئ قيوداً).
 * تجميع واحد بـ UNION في قاعدة البيانات مع ترقيم صفحات، ومقيّد بمخازن المستخدم.
 */
class StockJournalController extends InventoryController
{
    public const KINDS = [
        'receipt' => 'استلام مشتريات',
        'transfer' => 'صرفية أصناف',
        'waste' => 'هالك',
        'count' => 'تسوية جرد',
        'opening' => 'رصيد أول المدة',
    ];

    public function __construct(private WarehouseAccess $access) {}

    /** GET /api/inventory/journal?from=&to=&type=&search=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $ids = $this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user);
        $search = trim((string) ($data['search'] ?? ''));

        $query = DB::query()->fromSub($this->union($ids), 'j')
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('day', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('day', '<=', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('reference_no', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('account_name', 'like', "%{$search}%")));

        $showCosts = CostVisibility::canView($user);
        $totals = (clone $query)->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();

        // id من جداول مختلفة لا يصلح وحده لفك التعادل في اللحظة نفسها: رصيد أول المدة، ثم المستندات، ثم تسويات الجرد.
        $page = $query->orderBy('day')->orderBy('at')->orderBy('sort_rank')->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 50), 1), 200));

        $tz = config('inventory.business_timezone', config('app.timezone'));
        $page->getCollection()->transform(function ($row) use ($tz, $showCosts) {
            $time = $row->doc_time
                ? substr((string) $row->doc_time, 0, 5)
                : ($row->at ? Carbon::parse($row->at, config('app.timezone'))->setTimezone($tz)->format('H:i') : null);

            return [
                'source' => $row->source,
                'id' => (int) $row->id,
                'number' => $row->number,
                'kind' => $row->kind,
                'kind_label' => self::KINDS[$row->kind].($row->status === 'reversed' ? ' (معكوس)' : ''),
                'status' => $row->status,
                'day' => $row->day,
                'time' => $time,
                'reference_no' => $row->reference_no,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'description' => $row->description ?: $this->defaultDescription($row),
                'amount' => $showCosts ? (string) $row->amount : null,
                'warehouse_id' => (int) $row->warehouse_id,
                'purchase_bill_id' => $row->purchase_bill_id ? (int) $row->purchase_bill_id : null,
            ];
        });

        return $this->success('تم', [
            ...$page->toArray(),
            'summary' => ['count' => (int) $totals->count, 'amount' => $showCosts ? (string) $totals->amount : null],
            'costs_visible' => $showCosts,
        ]);
    }

    /**
     * GET /api/inventory/journal/opening/{movement} — أسطر رصيد أول مدة سُجّل دفعة واحدة
     * (نفس المخزن والمستخدم ولحظة التسجيل)، مع حالة عكس كل سطر.
     */
    public function opening(Request $request, StockMovement $movement): JsonResponse
    {
        if ($movement->type !== 'opening' || $movement->source_type !== null || $movement->reversal_of_id !== null) {
            return $this->error('ليست حركة رصيد أول المدة.', 404);
        }
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($movement->warehouse_id))) {
            return $denied;
        }

        $lines = StockMovement::query()
            ->where('type', 'opening')->whereNull('source_type')->whereNull('reversal_of_id')
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('occurred_at', $movement->occurred_at)
            ->where(fn ($q) => $movement->created_by ? $q->where('created_by', $movement->created_by) : $q->whereNull('created_by'))
            ->with(['stockItem:id,code,name,base_unit_id', 'stockItem.baseUnit:id,name', 'enteredUnit:id,name', 'reversedBy:id,reversal_of_id'])
            ->orderBy('id')
            ->get();

        $showCosts = CostVisibility::canView($request->user());

        return $this->success('تم', [
            'number' => 'OPN-'.str_pad((string) $lines->min('id'), 6, '0', STR_PAD_LEFT),
            'warehouse' => Warehouse::find($movement->warehouse_id, ['id', 'name', 'code']),
            'day' => $movement->accounting_day?->toDateString(),
            'notes' => $movement->notes,
            'created_by' => $movement->creator?->name,
            'costs_visible' => $showCosts,
            'lines' => $lines->map(fn (StockMovement $m) => [
                'movement_id' => $m->id,
                'stock_item_id' => $m->stock_item_id,
                'code' => $m->stockItem?->code,
                'name' => $m->stockItem?->name,
                'quantity' => (string) $m->quantity,
                'unit' => $m->stockItem?->baseUnit?->name,
                'entered_quantity' => $m->entered_quantity,
                'entered_unit' => $m->enteredUnit?->name,
                'unit_cost' => $showCosts ? (string) $m->unit_cost : null,
                'total_cost' => $showCosts ? (string) $m->total_cost : null,
                'is_reversed' => $m->reversedBy !== null,
            ])->values(),
        ]);
    }

    /** @param  array<int>|null  $ids  مخازن المستخدم، أو null لمن يرى كل المخازن */
    private function union(?array $ids): Builder
    {
        $documents = DB::table('stock_documents as d')
            ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 'd.to_warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->whereIn('d.status', ['posted', 'reversed'])
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('d.warehouse_id', $ids)->orWhereIn('d.to_warehouse_id', $ids)
                ->orWhereExists(fn ($e) => $e->from('stock_document_lines as l')->whereColumn('l.stock_document_id', 'd.id')->whereIn('l.to_warehouse_id', $ids))))
            ->selectRaw(<<<'SQL'
                'document' as source, d.id as id, d.number as number, d.type as kind, d.status as status,
                d.document_date as day, d.document_time as doc_time, d.posted_at as at, d.reference_no as reference_no,
                CASE d.type WHEN 'receipt' THEN s.code WHEN 'transfer' THEN tw.code ELSE w.code END as account_code,
                CASE d.type WHEN 'receipt' THEN s.name WHEN 'transfer' THEN tw.name ELSE w.name END as account_name,
                d.notes as description, d.total_cost as amount, d.warehouse_id as warehouse_id,
                d.purchase_bill_id as purchase_bill_id, w.name as warehouse_name, 1 as sort_rank
            SQL);

        $counts = DB::table('stock_counts as c')
            ->join('warehouses as w', 'w.id', '=', 'c.warehouse_id')
            // المعكوس يبقى في السجل (حركاته وعكسها في الكاردكس) بحالته "معكوس" مثل المستندات.
            ->whereIn('c.status', ['posted', 'reversed'])
            ->when($ids !== null, fn ($q) => $q->whereIn('c.warehouse_id', $ids))
            ->selectRaw(<<<'SQL'
                'count' as source, c.id as id, c.number as number, 'count' as kind, c.status as status,
                COALESCE(c.count_date, DATE(c.posted_at)) as day, NULL as doc_time, c.posted_at as at, NULL as reference_no,
                w.code as account_code, w.name as account_name, c.notes as description, c.variance_value as amount,
                c.warehouse_id as warehouse_id, NULL as purchase_bill_id, w.name as warehouse_name, 2 as sort_rank
            SQL);

        $openings = DB::table('stock_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.type', 'opening')->whereNull('m.source_type')->whereNull('m.reversal_of_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('m.warehouse_id', $ids))
            ->groupBy('m.warehouse_id', 'm.created_by', 'm.occurred_at', 'w.code', 'w.name')
            ->selectRaw(<<<'SQL'
                'opening' as source, MIN(m.id) as id, CONCAT('OPN-', LPAD(MIN(m.id), 6, '0')) as number, 'opening' as kind,
                'posted' as status, MIN(m.accounting_day) as day, NULL as doc_time, m.occurred_at as at, NULL as reference_no,
                w.code as account_code, w.name as account_name, MIN(m.notes) as description, SUM(m.total_cost) as amount,
                m.warehouse_id as warehouse_id, NULL as purchase_bill_id, w.name as warehouse_name, 0 as sort_rank
            SQL);

        return $documents->unionAll($counts)->unionAll($openings);
    }

    private function defaultDescription(object $row): string
    {
        return match ($row->kind) {
            'receipt' => "استلام إلى {$row->warehouse_name}",
            'transfer' => "صرف من {$row->warehouse_name}",
            'waste' => "هالك من {$row->warehouse_name}",
            'count' => "تسوية جرد {$row->warehouse_name}",
            default => "رصيد أول المدة — {$row->warehouse_name}",
        };
    }
}
