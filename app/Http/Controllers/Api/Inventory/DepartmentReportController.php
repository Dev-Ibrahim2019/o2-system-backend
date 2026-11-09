<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\DepartmentCountService;
use App\Services\Inventory\DepartmentReportService;
use App\Services\Inventory\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * صفحة الأقسام (/inventory/departments): قائمة الأقسام، وتفصيل القسم في فرع (أصناف البيع، استهلاك المواد،
 * الإحصائيات، الجرد). كل الأرقام مجمّعة في الخادم. القيم المشتقة من التكلفة تُفرغ بلا inventory.costs.view
 * (المبيعات تبقى). القسم بمخزن: صلاحية المخزن؛ بلا مخزن: وصول شامل فقط.
 *
 * اعتماد الجرد بالمسار الموجود POST counts/{id}/post، وعكسه POST counts/{id}/reverse.
 */
class DepartmentReportController extends InventoryController
{
    /** مفاتيح مالية مشتقة من التكلفة. */
    private const MONEY_KEYS = ['cost', 'profit', 'food_cost_percent', 'unit_cost', 'cost_share_percent', 'expected_value',
        'variance_value', 'shortage_value', 'surplus_value', 'total_cost'];

    public function __construct(private DepartmentReportService $reports, private DepartmentCountService $counts) {}

    /** GET /api/inventory/departments/summary?from=&to=&branch_id= */
    public function summary(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->periodRules() + ['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $p = $this->reports->period($data['from'] ?? null, $data['to'] ?? null);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        return $this->respond($request, $p, $this->reports->summary($request->user(), $p, $branchId));
    }

    /** GET /api/inventory/departments/{department}?branch_id= — رأس صفحة القسم. */
    public function show(Request $request, Department $department): JsonResponse
    {
        return $this->withUnit($request, $department, fn (Branch $branch, ?Warehouse $warehouse) => $this->success('تم', [
            ...$this->reports->unit($department, $branch, $warehouse, $request->user()),
            'costs_visible' => CostVisibility::canView($request->user()),
        ]));
    }

    /** GET /api/inventory/departments/{department}/sale-items?branch_id=&from=&to= */
    public function saleItems(Request $request, Department $department): JsonResponse
    {
        return $this->report($request, $department, fn (Branch $b, array $p) => $this->reports->saleItems($department->id, $b->id, $p));
    }

    /** GET /api/inventory/departments/{department}/consumption?branch_id=&from=&to= */
    public function consumption(Request $request, Department $department): JsonResponse
    {
        return $this->report($request, $department, fn (Branch $b, array $p) => $this->reports->consumption($department->id, $b->id, $p));
    }

    /** GET /api/inventory/departments/{department}/stats?branch_id=&from=&to= */
    public function stats(Request $request, Department $department): JsonResponse
    {
        return $this->report($request, $department, fn (Branch $b, array $p) => $this->reports->stats($department->id, $b->id, $p));
    }

    // ── الجرد ────────────────────────────────────────────────────────────────

    /** GET /api/inventory/departments/{department}/stock-count/preview?branch_id=&from=&count_id= */
    public function countPreview(Request $request, Department $department): JsonResponse
    {
        $extra = $this->validateAr($request, ['count_id' => ['nullable', 'integer', 'exists:stock_counts,id']]);

        return $this->withWarehouse($request, $department, function (Warehouse $w, array $p) use ($request, $extra) {
            $draft = null;
            if (! empty($extra['count_id'])) {
                $draft = StockCount::findOrFail($extra['count_id']);
                if ((int) $draft->warehouse_id !== $w->id || $draft->status !== 'draft') {
                    return $this->error('هذه الورقة ليست مسودة جرد لهذا القسم.', 422);
                }
            }

            return $this->respond($request, $p, $this->counts->preview($w, $p['from'], $draft));
        });
    }

    /** GET /api/inventory/departments/{department}/stock-count/movements?branch_id=&from=&stock_item_id=&column= */
    public function countMovements(Request $request, Department $department): JsonResponse
    {
        $extra = $this->validateAr($request, [
            'stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'column' => ['required', Rule::in([...array_keys(DepartmentCountService::COLUMNS), 'other'])],
        ]);

        return $this->withWarehouse($request, $department, fn (Warehouse $w, array $p) => $this->respond($request, $p,
            $this->counts->movements($w, (int) $extra['stock_item_id'], $extra['column'], $p['from'])));
    }

    /** GET /api/inventory/departments/{department}/stock-counts?branch_id= — سجل جرود القسم. */
    public function countHistory(Request $request, Department $department): JsonResponse
    {
        return $this->withWarehouse($request, $department, fn (Warehouse $w, array $p) => $this->respond($request, $p,
            ['rows' => $this->counts->history($w)]));
    }

    /** GET /api/inventory/departments/{department}/stock-counts/{count}?branch_id= — جرد معتمد/معكوس كما سُجّل. */
    public function countShow(Request $request, Department $department, StockCount $count): JsonResponse
    {
        return $this->withWarehouse($request, $department, function (Warehouse $w, array $p) use ($request, $count) {
            if ((int) $count->warehouse_id !== $w->id) {
                return $this->error('هذا الجرد ليس لمخزن هذا القسم.', 404);
            }
            if ($count->status === 'draft') {
                return $this->respond($request, $p, ['count' => $this->counts->countInfo($count), 'lines' => [], 'totals' => []]);
            }

            return $this->respond($request, $p, $this->counts->posted($count));
        });
    }

    /** POST /api/inventory/departments/{department}/stock-counts {branch_id, count_id?, notes?, lines:[{stock_item_id, quantity|null}]} */
    public function countSave(Request $request, Department $department): JsonResponse
    {
        $data = $this->validateAr($request, [
            'count_id' => ['nullable', 'integer', 'exists:stock_counts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->withWarehouse($request, $department, fn (Warehouse $w, array $p) => $this->attempt(function () use ($request, $w, $p, $data) {
            $draft = ! empty($data['count_id']) ? StockCount::findOrFail($data['count_id']) : null;
            $count = $this->counts->saveDraft($w, $draft, $data['lines'], $data['notes'] ?? null, $p['from'], $request->user());

            return $this->success('تم حفظ الجرد كمسودة', $this->counts->countInfo($count), $draft ? 200 : 201);
        }));
    }

    // ── التصدير ──────────────────────────────────────────────────────────────

    /** GET /api/inventory/departments/summary/export?from=&to=&branch_id= */
    public function summaryExport(Request $request): BinaryFileResponse
    {
        $data = $this->validateAr($request, $this->periodRules() + ['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $p = $this->reports->period($data['from'] ?? null, $data['to'] ?? null);
        $result = $this->reports->summary($request->user(), $p, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $m = $this->moneyFn($request);

        return $this->download('الأقسام', ['القسم', 'الفرع', 'المخزن', 'أصناف البيع', 'الطلبات', 'المبيعات ₪', 'تكلفة الاستهلاك ₪', 'الربح ₪', 'نسبة التكلفة %', 'طلبات بلا خصم'],
            array_map(fn ($r) => [$r['department']['name'], $r['branch']['name'], $r['warehouse']['name'] ?? '—', $r['sale_items_count'], $r['orders'],
                $r['sales'], $m($r['cost']), $m($r['profit']), $m($r['food_cost_percent']), $r['undeducted_orders']], $result['rows']),
            "departments-{$p['from']}_{$p['to']}.xlsx");
    }

    /** GET /api/inventory/departments/{department}/export?table=sale_items|consumption|count&branch_id=&from=&to=&count_id= */
    public function export(Request $request, Department $department): JsonResponse|BinaryFileResponse
    {
        $extra = $this->validateAr($request, [
            'table' => ['required', Rule::in(['sale_items', 'consumption', 'count'])],
            'count_id' => ['nullable', 'integer', 'exists:stock_counts,id'],
        ]);
        // تصدير المبيعات بصلاحية التحليلات؛ تصدير الجرد تكفيه صلاحية الجرد (المسار يقبل أياً منهما).
        if ($extra['table'] !== 'count' && ! $request->user()->can('inventory.analytics.view')) {
            return $this->error('لا تملك صلاحية تقارير المبيعات.', 403);
        }
        $m = $this->moneyFn($request);

        return $this->withUnit($request, $department, function (Branch $branch, ?Warehouse $w) use ($request, $department, $extra, $m) {
            $p = $this->periodFrom($request);
            $name = $department->nameAr ?: $department->name;
            if ($extra['table'] === 'sale_items') {
                $rows = [];
                foreach ($this->reports->saleItems($department->id, $branch->id, $p)['rows'] as $r) {
                    $rows[] = [$r['code'], $r['name'], $r['quantity'], $r['sales'], $m($r['cost']), $m($r['profit']), $m($r['food_cost_percent']), $r['share_percent'], '', '', ''];
                    foreach ($r['materials'] as $mat) {
                        $rows[] = ['', '', '', '', '', '', '', '', $mat['name'], "{$mat['quantity']} {$mat['base_unit']}", $m($mat['cost'])];
                    }
                }

                return $this->download("أصناف البيع — {$name}", ['الكود', 'صنف البيع', 'الكمية المباعة', 'المبيعات ₪', 'تكلفة الاستهلاك ₪', 'الربح ₪',
                    'نسبة التكلفة %', 'من مبيعات القسم %', 'المادة', 'الكمية', 'التكلفة ₪'], $rows,
                    "department-{$department->id}-sale-items-{$p['from']}_{$p['to']}.xlsx");
            }
            if ($extra['table'] === 'consumption') {
                return $this->download("استهلاك المواد — {$name}", ['الكود', 'المادة', 'الوحدة', 'الكمية', 'التكلفة ₪', 'تكلفة الوحدة ₪', 'من التكلفة %', 'أصناف البيع'],
                    array_map(fn ($r) => [$r['code'], $r['name'], $r['base_unit'], $r['quantity'], $m($r['cost']), $m($r['unit_cost']), $m($r['cost_share_percent']),
                        implode('، ', array_map(fn ($i) => "{$i['name']} ({$i['quantity']})", $r['items']))],
                        $this->reports->consumption($department->id, $branch->id, $p)['rows']),
                    "department-{$department->id}-consumption-{$p['from']}_{$p['to']}.xlsx");
            }

            if (! $w) {
                return $this->error('القسم غير مربوط بمخزن في هذا الفرع.', 422);
            }
            if ($denied = $this->denyUnlessCanAccess($request, $w)) {
                return $denied;
            }
            $count = ! empty($extra['count_id']) ? StockCount::findOrFail($extra['count_id']) : null;
            if ($count && (int) $count->warehouse_id !== $w->id) {
                return $this->error('هذا الجرد ليس لمخزن هذا القسم.', 404);
            }
            if ($count && $count->status !== 'draft') {
                $data = $this->counts->posted($count);

                return $this->download("جرد {$count->number} — {$name}", ['الكود', 'المادة', 'الوحدة', 'المتوقع', 'الفعلي', 'الفرق', 'تكلفة الوحدة ₪', 'الفرق ₪', 'الفرق %'],
                    array_map(fn ($r) => [$r['code'], $r['name'], $r['base_unit'], $r['expected'], $r['counted'], $r['variance_quantity'], $m($r['unit_cost']),
                        $m($r['variance_value']), $r['variance_percent']], $data['lines']),
                    "count-{$count->number}.xlsx");
            }
            $data = $this->counts->preview($w, $p['from'], $count);

            return $this->download("جرد {$name}", ['الكود', 'المادة', 'الوحدة', 'الافتتاحي', 'الصرفيات', 'المحوّل من القسم', 'استهلاك البيع', 'الهالك', 'أخرى',
                'المتوقع', 'الفعلي', 'الفرق', 'تكلفة الوحدة ₪', 'الفرق ₪', 'الفرق %'],
                array_map(fn ($r) => [$r['code'], $r['name'], $r['base_unit'], $r['opening'], $r['transfers_in'], $r['transfers_out'], $r['sale'], $r['waste'],
                    $r['other'], $r['expected'], $r['counted'], $r['variance_quantity'], $m($r['unit_cost']), $m($r['variance_value']), $r['variance_percent']], $data['lines']),
                "department-{$department->id}-count.xlsx");
        });
    }

    // ── داخلي ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function periodRules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
    }

    /** @return array{from:string, to:string, days:int} */
    private function periodFrom(Request $request): array
    {
        $data = $this->validateAr($request, $this->periodRules());

        return $this->reports->period($data['from'] ?? null, $data['to'] ?? null);
    }

    /** يتحقق من الفرع وصلاحية رؤية القسم فيه، ثم ينفّذ. */
    private function withUnit(Request $request, Department $department, callable $then): JsonResponse|BinaryFileResponse
    {
        $data = $this->validateAr($request, ['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::findOrFail($data['branch_id']);
        $warehouse = $this->reports->warehouseOf($department->id, $branch->id);
        if (! $this->reports->canView($request->user(), $warehouse)) {
            return $this->error($warehouse ? 'لا تملك صلاحية الوصول لمخزن هذا القسم.' : 'لا تملك صلاحية الوصول لهذا القسم.', 403);
        }

        return $then($branch, $warehouse);
    }

    private function report(Request $request, Department $department, callable $build): JsonResponse
    {
        return $this->withUnit($request, $department, function (Branch $b) use ($request, $build) {
            $p = $this->periodFrom($request);

            return $this->respond($request, $p, $build($b, $p));
        });
    }

    /** للجرد: القسم يجب أن يكون مربوطاً بمخزن في الفرع، والكتابة تحتاج صلاحية تعديل على المخزن. */
    private function withWarehouse(Request $request, Department $department, callable $then): JsonResponse
    {
        return $this->withUnit($request, $department, function (Branch $b, ?Warehouse $w) use ($request, $then) {
            if (! $w) {
                return $this->error('القسم غير مربوط بمخزن في هذا الفرع — اربطه من شاشة المخازن ليُجرد.', 422);
            }
            if ($denied = $this->denyUnlessCanAccess($request, $w)) {
                return $denied;
            }

            return $then($w, $this->periodFrom($request));
        });
    }

    /** @param  array<string, mixed>  $result */
    private function respond(Request $request, array $p, array $result): JsonResponse
    {
        $money = CostVisibility::canView($request->user());

        return $this->success('تم', [
            ...($money ? $result : $this->stripMoney($result)),
            'filters' => $p,
            'costs_visible' => $money,
        ]);
    }

    private function moneyFn(Request $request): \Closure
    {
        $money = CostVisibility::canView($request->user());

        return fn ($v) => $money ? $v : null;
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function download(string $title, array $headings, array $rows, string $file): BinaryFileResponse
    {
        return response()->download(SimpleXlsx::write($title, $headings, $rows), $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

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
