<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المستندات المخزنية: استلام مشتريات، تحويل، هالك.
 * الوصول بحسب المخزن المصدر (أو الوجهة للعرض)، والكتابة/الترحيل/العكس لكلٍّ صلاحيته.
 */
class StockDocumentController extends InventoryController
{
    private const COST_FIELDS = ['total_cost'];

    private const LINE_COST_FIELDS = ['unit_cost', 'posted_cost'];

    public function __construct(private StockDocumentService $documents, private WarehouseAccess $access) {}

    /** GET /api/inventory/documents/meta — الأنواع وأسباب الهالك والموردون النشطون. */
    public function meta(): JsonResponse
    {
        return $this->success('تم', [
            'types' => collect(StockDocument::TYPES)->map(fn ($t, $k) => ['value' => $k, 'label' => $t['label']])->values(),
            'waste_reasons' => collect(StockDocument::WASTE_REASONS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'payment_terms']),
        ]);
    }

    /** GET /api/inventory/documents?type=&status=&warehouse_id=&supplier_id=&from=&to=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $page = StockDocument::query()
            ->with(['warehouse:id,name', 'toWarehouse:id,name', 'supplier:id,name', 'creator:id,name'])
            ->withCount('lines')
            ->when(! $this->access->hasGlobalAccess($user), function ($q) use ($user) {
                $ids = $this->access->assignedIds($user);
                $q->where(fn ($w) => $w->whereIn('warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where(fn ($w) => $w
                ->where('warehouse_id', $request->integer('warehouse_id'))
                ->orWhere('to_warehouse_id', $request->integer('warehouse_id'))))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('document_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('document_date', '<=', $request->query('to')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('supplier_invoice_no', 'like', "%{$search}%")))
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $page->getCollection()->transform(fn (StockDocument $d) => $this->present($request, $d, withLines: false));

        return $this->success('تم', $page);
    }

    /** GET /api/inventory/documents/{document} */
    public function show(Request $request, StockDocument $document): JsonResponse
    {
        if ($denied = $this->denyUnlessCanSee($request, $document)) {
            return $denied;
        }
        $document->load([
            'lines.stockItem:id,code,name,base_unit_id,tracks_expiry', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name',
            'lines.batch:id,code,expiry_date', 'lines.createdBatch:id,code,expiry_date',
            'warehouse:id,name,code', 'toWarehouse:id,name,code', 'supplier:id,name,code',
            'creator:id,name', 'poster:id,name', 'reverser:id,name',
        ]);

        return $this->success('تم', $this->present($request, $document));
    }

    /** POST /api/inventory/documents */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules(true));
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($data['warehouse_id']))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم حفظ المسودة',
            $this->present($request, $this->documents->create($data, $request->user())),
            201,
        ));
    }

    /** PUT /api/inventory/documents/{document} — للمسودة فقط. */
    public function update(Request $request, StockDocument $document): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules(false));
        foreach ([$document->warehouse_id, $data['warehouse_id']] as $warehouseId) {
            if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($warehouseId))) {
                return $denied;
            }
        }

        return $this->attempt(fn () => $this->success(
            'تم حفظ المسودة',
            $this->present($request, $this->documents->update($document, $data)),
        ));
    }

    /** DELETE /api/inventory/documents/{document} — للمسودة فقط. */
    public function destroy(Request $request, StockDocument $document): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(function () use ($document) {
            $this->documents->delete($document);

            return $this->success('تم حذف المسودة');
        });
    }

    /** POST /api/inventory/documents/{document}/post */
    public function post(Request $request, StockDocument $document): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم ترحيل المستند',
            $this->present($request, $this->documents->post($document, $request->user())),
        ));
    }

    /** POST /api/inventory/documents/{document}/reverse {reason} */
    public function reverse(Request $request, StockDocument $document): JsonResponse
    {
        $data = $this->validateAr($request, ['reason' => ['required', 'string', 'max:500']]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم عكس المستند',
            $this->present($request, $this->documents->reverse($document, $data['reason'], $request->user())),
        ));
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys(StockDocument::TYPES))],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.waste_reason' => ['nullable', Rule::in(array_keys(StockDocument::WASTE_REASONS))],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'destination' => ['nullable', 'string', 'max:150'],
            'delivery_status' => ['nullable', Rule::in(['delivered', 'undelivered'])],
            'lines.*.batch_id' => ['nullable', 'integer', 'exists:stock_batches,id'],
            'lines.*.direction' => ['nullable', Rule::in(['in', 'out'])],
            'lines.*.cartons' => ['nullable', 'numeric', 'min:0'],
            'lines.*.production_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.value_ratio' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.is_prime' => ['nullable', 'boolean'],
            'lines.*.sale_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** PATCH /api/inventory/documents/{document}/delivery {delivery_status, destination?} — حالة فاتورة الصرف. */
    public function delivery(Request $request, StockDocument $document): JsonResponse
    {
        $data = $this->validateAr($request, [
            'delivery_status' => ['required', Rule::in(['delivered', 'undelivered'])],
            'destination' => ['nullable', 'string', 'max:150'],
        ]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم تحديث حالة الفاتورة', $this->present($request,
            $this->documents->updateDelivery($document, $data['delivery_status'], $data['destination'] ?? $document->destination))));
    }

    private function denyUnlessCanSee(Request $request, StockDocument $document): ?JsonResponse
    {
        $user = $request->user();
        $visible = $this->access->canAccess($user, $document->warehouse_id)
            || ($document->to_warehouse_id && $this->access->canAccess($user, $document->to_warehouse_id));

        return $visible ? null : $this->error('لا تملك صلاحية الوصول لهذا المخزن.', 403);
    }

    /**
     * التكاليف تظهر لمن يملك inventory.costs.view، ولمُدخل الاستلام (يكتب التكلفة بنفسه).
     *
     * @return array<string, mixed>
     */
    private function present(Request $request, StockDocument $doc, bool $withLines = true): array
    {
        $user = $request->user();
        $showCosts = CostVisibility::canView($user)
            || ($doc->type === StockDocument::TYPE_RECEIPT && $user->can('inventory.documents.manage'));

        $row = [
            ...$doc->toArray(),
            'type_label' => StockDocument::TYPES[$doc->type]['label'],
            'costs_visible' => $showCosts,
            'breakdown' => $withLines ? $this->documents->breakdownStats($doc) : null,
        ];
        if (! $withLines) {
            unset($row['lines']);
        }
        if (! $showCosts) {
            $row = CostVisibility::strip($row, self::COST_FIELDS);
            if (isset($row['lines'])) {
                $row['lines'] = array_map(fn ($l) => CostVisibility::strip($l, self::LINE_COST_FIELDS), $row['lines']);
            }
        }

        return $row;
    }
}
