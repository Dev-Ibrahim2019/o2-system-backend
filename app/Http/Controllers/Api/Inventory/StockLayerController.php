<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockItem;
use App\Models\StockLayer;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockLayerReportService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الدفعات (Cost Layers): دفعات الصنف المتاحة لشاشة الصرف (التحديد المعيّن)، وتقارير الدفعات: تفصيل دفعات صنف،
 * تتبّع دفعة، تكلفة الصرف حسب الفترة، وفحص المطابقة. التكاليف تُخفى بلا inventory.costs.view.
 */
class StockLayerController extends InventoryController
{
    private const MONEY = ['unit_cost', 'value', 'total_cost', 'cost', 'adjustments', 'total', 'old_unit_cost', 'new_unit_cost',
        'ledger_value', 'layer_value', 'estimated_cost'];

    public function __construct(private StockLedger $ledger, private StockLayerReportService $reports, private WarehouseAccess $access) {}

    /** GET /api/inventory/items/{item}/layers?warehouse_id=&quantity= — الدفعات المتاحة بالأقدم أولاً (+ تقدير تكلفة الكمية بالأقدم أولاً). */
    public function available(Request $request, StockItem $item): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $layers = $this->ledger->layers()->openLayers($warehouse->id, $item->id)->map(fn ($l) => $this->reports->presentLayer($l))->all();

        return $this->respond($request, [
            'layers' => $layers,
            'estimated_cost' => isset($data['quantity']) ? $this->ledger->layers()->estimateIssue($warehouse->id, $item->id, (string) $data['quantity']) : null,
            'latest_cost' => $this->ledger->layers()->latestCost($warehouse->id, $item->id),
        ]);
    }

    /** GET /api/inventory/layers/{layer} — تتبّع دفعة: من أين جاءت وأين صُرفت. */
    public function show(Request $request, StockLayer $layer): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($layer->warehouse_id))) {
            return $denied;
        }

        return $this->respond($request, $this->reports->trace($layer));
    }

    /** GET /api/inventory/reports/layers?stock_item_id=&warehouse_ids[]=&open=1 — دفعات صنف (تفصيل تقرير الأرصدة). */
    public function itemLayers(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
            'open' => ['nullable', 'boolean'],
        ]);

        return $this->respond($request, ['layers' => $this->reports->layers((int) $data['stock_item_id'],
            $this->scope($request, $data['warehouse_ids'] ?? null), (bool) ($data['open'] ?? true))]);
    }

    /** GET /api/inventory/reports/issue-costs?from=&to=&warehouse_ids[]= — تكلفة الصرف واستهلاك المبيعات حسب الفترة. */
    public function issueCosts(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ]);
        $day = AccountingDay::fromConfig();
        $to = $data['to'] ?? $day->of(now());
        $from = $data['from'] ?? substr($to, 0, 8).'01';

        return $this->respond($request, [...$this->reports->issueCosts($from, $to, $this->scope($request, $data['warehouse_ids'] ?? null)),
            'filters' => ['from' => $from, 'to' => $to]]);
    }

    /** GET /api/inventory/reports/layers-check — فحص المطابقة لمخازن المستخدم. */
    public function check(Request $request): JsonResponse
    {
        return $this->respond($request, $this->reports->reconcile($this->scope($request, null)));
    }

    /** @return array<int>|null المخازن المختارة ضمن ما يراه المستخدم (null = الكل لصاحب الوصول الشامل). */
    private function scope(Request $request, ?array $ids): ?array
    {
        $user = $request->user();
        $visible = $this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user);
        if ($ids === null || $ids === []) {
            return $visible;
        }
        $ids = array_map('intval', $ids);

        return $visible === null ? $ids : array_values(array_intersect($ids, $visible));
    }

    /** @param  array<string, mixed>  $data */
    private function respond(Request $request, array $data): JsonResponse
    {
        $costs = CostVisibility::canView($request->user());

        return $this->success('تم', [...($costs ? $data : $this->strip($data)), 'costs_visible' => $costs]);
    }

    private function strip(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY, true)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->strip($v);
            }
        }

        return $data;
    }
}
