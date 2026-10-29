<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockBatch;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\InventoryAnalyticsService;
use App\Services\Inventory\QualityService;
use App\Services\Inventory\ShawarmaService;
use App\Services\Inventory\SimpleXlsx;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * شاشات "الجودة والرقابة" المنقولة من O2: الدفعات، الإنتاجية اليومية، محضر الإتلاف، الشاورما،
 * قالب القسم في الفرع، والتقارير. الوصول بحسب المخزن (= القسم في الفرع) ومستواه.
 */
class QualityController extends InventoryController
{
    public function __construct(
        private QualityService $quality,
        private ShawarmaService $shawarma,
        private StockCountService $counts,
        private WarehouseAccess $access,
    ) {}

    /** GET quality/batches?warehouse_id=&stock_item_id=&available=1&search= */
    public function batches(Request $request): JsonResponse
    {
        $user = $request->user();
        $warehouses = $request->filled('warehouse_id') ? [$request->integer('warehouse_id')]
            : ($this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user));
        if ($request->filled('warehouse_id') && ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($request->integer('warehouse_id'))))) {
            return $denied;
        }
        $rows = $this->quality->batches($warehouses, $request->integer('stock_item_id') ?: null, $request->boolean('available'), trim((string) $request->query('search')) ?: null);
        if (! CostVisibility::canView($user)) {
            $rows = array_map(fn ($r) => CostVisibility::strip($r, ['unit_cost']), $rows);
        }

        return $this->success('تم', $rows);
    }

    /** GET quality/productivity?warehouse_id=&day= */
    public function productivity(Request $request): JsonResponse|BinaryFileResponse
    {
        [$warehouse, $day, $denied] = $this->warehouseDay($request);
        if ($denied) {
            return $denied;
        }
        $data = $this->quality->productivity($warehouse, $day);
        if ($request->boolean('export')) {
            return $this->xlsx("إنتاجية {$day}", ['الصنف', 'الوحدة', 'رصيد البداية', 'المستلمة', 'المحوَّل للخارج', 'المتبقي', 'الإتلاف', 'المستهلك', 'المفترض', 'العجز', 'العجز %', 'الوضع'],
                array_map(fn ($r) => [$r['name'], $r['base_unit'], $r['opening'], $r['received'], $r['transferred_out'], $r['remaining'], $r['waste'],
                    $r['consumed'], $r['theoretical'], $r['deficit'], $r['deficit_percent'], ['better' => 'أفضل', 'worse' => 'أسوأ', 'stable' => 'مستقر'][$r['trend']] ?? ''],
                    $data['rows']), "productivity-{$warehouse->id}-{$day}.xlsx");
        }
        if (! CostVisibility::canView($request->user())) {
            $data['rows'] = array_map(fn ($r) => CostVisibility::strip($r, ['deficit_value']), $data['rows']);
            $data['totals'] = CostVisibility::strip($data['totals'], ['consumed_value', 'theoretical_value', 'deficit_value']);
        }

        return $this->success('تم', $data);
    }

    /** POST quality/daily-count {warehouse_id, day} — ورقة الجرد اليومي للقسم (تُنشأ أو تُرجَع). */
    public function dailyCount(Request $request): JsonResponse
    {
        [$warehouse, $day, $denied] = $this->warehouseDay($request);
        if ($denied) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم', ['id' => $this->counts->daily($warehouse, $day, $request->user())->id]));
    }

    /** GET quality/waste-minutes?warehouse_id=&day= — بيانات "محضر الإتلاف" للطباعة. */
    public function wasteMinutes(Request $request): JsonResponse
    {
        [$warehouse, $day, $denied] = $this->warehouseDay($request);
        if ($denied) {
            return $denied;
        }
        $data = $this->quality->wasteMinutes($warehouse, $day);
        if (! CostVisibility::canView($request->user())) {
            $data['rows'] = array_map(fn ($r) => CostVisibility::strip($r, ['value']), $data['rows']);
            $data['total_value'] = null;
        }

        return $this->success('تم', $data);
    }

    /** GET quality/shawarma?warehouse_id=&from=&to= — يوم أو فترة لقسم الشاورما. */
    public function shawarma(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $today = AccountingDay::fromConfig()->of(now());
        $from = $data['from'] ?? $today;
        $to = max($from, $data['to'] ?? $from);

        return $this->attempt(fn () => $this->success('تم', $this->shawarma->period($warehouse, $from, $to)));
    }

    /** POST quality/skewers/{batch}/export — تصدير سيخ (يخرج من مخزن القسم). */
    public function exportSkewer(Request $request, StockBatch $batch): JsonResponse
    {
        $warehouseId = (int) \Illuminate\Support\Facades\DB::table('stock_batch_balances')->where('batch_id', $batch->id)->where('quantity', '>', 0)->value('warehouse_id');
        if ($warehouseId && ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($warehouseId)))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم تصدير السيخ', $this->shawarma->exportSkewer($batch, $request->user())));
    }

    /** GET quality/department-settings — كل قسم في كل فرع مع قالبه ومخازنه. */
    public function departmentSettings(): JsonResponse
    {
        return $this->success('تم', $this->quality->departmentSettings());
    }

    /** PUT quality/department-settings {department_id, branch_id, has_meals, has_factory, has_showroom, is_shawarma, ...} */
    public function saveDepartmentSetting(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'has_meals' => ['required', 'boolean'],
            'has_factory' => ['required', 'boolean'],
            'has_showroom' => ['required', 'boolean'],
            'is_shawarma' => ['required', 'boolean'],
            'meat_category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'spice_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'skewer_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
        ]);

        return $this->attempt(fn () => $this->success('تم حفظ إعداد القسم',
            $this->quality->saveDepartmentSetting($data['department_id'], $data['branch_id'], $data)));
    }

    /** GET quality/reports/{kind}?warehouse_id= | department_id=&branch_id= &period... */
    public function report(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['main', 'department', 'factory'], true), 404);
        $f = app(InventoryAnalyticsService::class)->filters($request->only(['period', 'from', 'to', 'warehouse_id']), $request->user());

        if ($kind === 'factory') {
            $data = $this->validateAr($request, [
                'department_id' => ['required', 'integer', 'exists:departments,id'],
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
            ]);
            $result = $this->quality->factoryReport($f, $data['department_id'], $data['branch_id']);
        } else {
            $this->validateAr($request, ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);
            $warehouse = Warehouse::findOrFail($request->integer('warehouse_id'));
            if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
                return $denied;
            }
            $result = $kind === 'main' ? $this->quality->mainWarehouseReport($f, $warehouse) : $this->quality->departmentReport($f, $warehouse);
        }

        return $this->success('تم', [...$result, 'period' => ['from' => $f['from'], 'to' => $f['to']], 'costs_visible' => CostVisibility::canView($request->user())]);
    }

    /** @return array{0: Warehouse, 1: string, 2: ?JsonResponse} */
    private function warehouseDay(Request $request): array
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'day' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        return [$warehouse, $data['day'] ?? AccountingDay::fromConfig()->of(now()), $this->denyUnlessCanAccess($request, $warehouse)];
    }

    private function xlsx(string $title, array $headings, array $rows, string $name): BinaryFileResponse
    {
        return response()->download(SimpleXlsx::write($title, $headings, $rows), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
