<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المستندات المخزنية: استلام مشتريات، صرفية أصناف (تحويل)، هالك.
 * الوصول بحسب المخزن المصدر (أو إحدى الوجهات للعرض)، والكتابة/الترحيل/العكس لكلٍّ صلاحيته.
 */
class StockDocumentController extends InventoryController
{
    private const COST_FIELDS = ['total_cost'];

    private const LINE_COST_FIELDS = ['unit_cost', 'posted_cost'];

    /** عكس مستند صرف بعد تأكيد استلامه (migration 2027_03_08_000001). */
    private const REVERSE_RECEIVED = 'inventory.documents.reverse_received';

    /** استلام مشتريات بدون فاتورة (2027_03_09_000001) — الاستلام العادي صار عبر فاتورة المشتريات. */
    private const DIRECT_RECEIPT = 'inventory.receipts.direct';

    private const DETAIL_RELATIONS = [
        'lines.stockItem:id,code,name,base_unit_id', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name',
        'lines.toWarehouse:id,name,code', 'receipts.receiver:id,name',
        'warehouse:id,name,code', 'toWarehouse:id,name,code', 'supplier:id,name,code',
        'creator:id,name', 'poster:id,name', 'reverser:id,name', 'receiver:id,name',
    ];

    public function __construct(private StockDocumentService $documents, private WarehouseAccess $access) {}

    /** GET /api/inventory/documents/meta — الأنواع وأسباب الهالك والموردون النشطون، واليوم المحاسبي ووقته الآن. */
    public function meta(): JsonResponse
    {
        $tz = config('inventory.business_timezone', config('app.timezone'));

        return $this->success('تم', [
            'types' => collect(StockDocument::TYPES)->map(fn ($t, $k) => ['value' => $k, 'label' => $t['label']])->values(),
            'waste_reasons' => collect(StockDocument::WASTE_REASONS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'payment_terms']),
            // "اليوم" في الواجهة = اليوم المحاسبي (02:00 → 02:00) من الخادم، لا تاريخ الجهاز.
            'accounting_today' => AccountingDay::fromConfig()->of(now()),
            'now_time' => now()->setTimezone($tz)->format('H:i'),
        ]);
    }

    /**
     * GET /api/inventory/documents?type=&status=&warehouse_id=&to_warehouse_id=&supplier_id=&receipt=&from=&to=&search=&order=&page=
     *
     * receipt (الصرفية): received | partial | pending. summary = عدد المستندات المطابقة وإجمالي قيمة المرحّل منها.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $toWarehouse = $request->integer('to_warehouse_id');

        $query = $this->visible(StockDocument::query(), $user)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                $id = $request->integer('warehouse_id');
                $q->where(fn ($w) => $w->where('warehouse_id', $id)->orWhere('to_warehouse_id', $id)
                    ->orWhereHas('lines', fn ($l) => $l->where('to_warehouse_id', $id)));
            })
            ->when($toWarehouse > 0, fn ($q) => $q->where(fn ($w) => $w->where('to_warehouse_id', $toWarehouse)
                ->orWhereHas('lines', fn ($l) => $l->where('to_warehouse_id', $toWarehouse))))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('received'), fn ($q) => $request->boolean('received')
                ? $q->whereNotNull('received_at')
                : $q->whereNull('received_at'))
            ->when($request->filled('receipt'), fn ($q) => match ($request->query('receipt')) {
                'received' => $q->whereNotNull('received_at'),
                'partial' => $q->whereNull('received_at')->whereHas('receipts'),
                'pending' => $q->where('status', StockDocument::STATUS_POSTED)->whereNull('received_at')->whereDoesntHave('receipts'),
                default => $q,
            })
            ->when($request->filled('from'), fn ($q) => $q->where('document_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('document_date', '<=', $request->query('to')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                ->orWhere('reference_no', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")));

        $summary = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'posted' THEN total_cost ELSE 0 END), 0) as posted_total")
            ->first();

        $asc = $request->query('order') === 'asc';
        $page = $query
            ->with(['warehouse:id,name,code', 'toWarehouse:id,name,code', 'supplier:id,name', 'creator:id,name', 'receiver:id,name',
                'lines:id,stock_document_id,to_warehouse_id', 'receipts:id,stock_document_id,warehouse_id'])
            ->withCount('lines')
            ->orderBy('document_date', $asc ? 'asc' : 'desc')
            ->orderBy('id', $asc ? 'asc' : 'desc')
            ->paginate(min(max($request->integer('per_page', 25), 1), 200));

        $page->getCollection()->transform(fn (StockDocument $d) => $this->present($request, $d, withLines: false));

        $showCosts = CostVisibility::canView($user);
        $result = $page->toArray();
        $result['summary'] = [
            'count' => (int) $summary->count,
            'posted_total' => $showCosts ? (string) $summary->posted_total : null,
        ];

        return $this->success('تم', $result);
    }

    /**
     * GET /api/inventory/documents/navigate?type=&dir=first|prev|next|last&id= — أو ?type=&number=
     *
     * التنقل بين مستندات النوع نفسه بترتيب الإنشاء، ضمن ما يراه المستخدم. number يقبل الرقم كاملاً (TRF-000012)
     * أو تسلسله فقط (12). يعيد {id, number} أو null إن لم يوجد.
     */
    public function navigate(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'type' => ['required', Rule::in(array_keys(StockDocument::TYPES))],
            'dir' => ['nullable', Rule::in(['first', 'prev', 'next', 'last'])],
            'id' => ['nullable', 'integer'],
            'number' => ['nullable', 'string', 'max:40'],
        ]);
        $query = $this->visible(StockDocument::query(), $request->user())->where('type', $data['type']);

        if (! empty($data['number'])) {
            $number = strtoupper(trim($data['number']));
            $doc = ctype_digit($number)
                ? $query->whereKey((int) $number)->first(['id', 'number'])
                : $query->where('number', $number)->first(['id', 'number']);

            return $this->success('تم', $doc);
        }

        $id = (int) ($data['id'] ?? 0);
        $doc = match ($data['dir'] ?? 'last') {
            'first' => $query->orderBy('id')->first(['id', 'number']),
            'prev' => $query->where('id', '<', $id)->orderByDesc('id')->first(['id', 'number']),
            'next' => $query->where('id', '>', $id)->orderBy('id')->first(['id', 'number']),
            default => $query->orderByDesc('id')->first(['id', 'number']),
        };

        return $this->success('تم', $doc);
    }

    /** GET /api/inventory/documents/{document} */
    public function show(Request $request, StockDocument $document): JsonResponse
    {
        if ($denied = $this->denyUnlessCanSee($request, $document)) {
            return $denied;
        }
        $document->load(self::DETAIL_RELATIONS);

        return $this->success('تم', $this->present($request, $document));
    }

    /** POST /api/inventory/documents */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules(true));
        if ($data['type'] === StockDocument::TYPE_RECEIPT && ! $request->user()->can(self::DIRECT_RECEIPT)) {
            return $this->error('الاستلام من المورّدين يتم بفاتورة المشتريات. الاستلام بدون فاتورة يحتاج صلاحية خاصة.', 403);
        }
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

    /** POST /api/inventory/documents/{document}/duplicate — مسودة جديدة من مستند صرف (بتاريخ اليوم، بلا استلام). */
    public function duplicate(Request $request, StockDocument $document): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم إنشاء مسودة مكرّرة',
            $this->present($request, $this->documents->duplicate($document, $request->user())),
            201,
        ));
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
        if ($document->purchase_bill_id) {
            return $this->error('هذا الاستلام جزء من فاتورة مشتريات — اعكس الفاتورة نفسها.', 422);
        }
        if ($document->hasAnyReceipt() && ! $request->user()->can(self::REVERSE_RECEIVED)) {
            return $this->error('استلام هذا المستند مؤكَّد — عكسه يحتاج صلاحية المدير.', 403);
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
            'reference_no' => ['nullable', 'string', 'max:64'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'document_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'quantities_only' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'lines.*.to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.waste_reason' => ['nullable', Rule::in(array_keys(StockDocument::WASTE_REASONS))],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'destination' => ['nullable', 'string', 'max:150'],
            'delivery_status' => ['nullable', Rule::in(['delivered', 'undelivered'])],
        ];
    }

    /**
     * PATCH /api/inventory/documents/{document}/delivery {delivery_status?: delivered, warehouse_id?, destination?}
     *
     * delivered = تأكيد الاستلام الفعلي: لكل وجهة يملك المستخدم عليها مستوى "تعديل" (أو الوجهة warehouse_id وحدها)،
     * ويخزّن من استلم ومتى. لا يُلغى التأكيد (undelivered مرفوضة بعد الاستلام). الجهة وحدها تُعدَّل بصلاحية المصدر.
     */
    public function delivery(Request $request, StockDocument $document): JsonResponse
    {
        $data = $this->validateAr($request, [
            'delivery_status' => ['nullable', Rule::in(['delivered', 'undelivered'])],
            'warehouse_id' => ['nullable', 'integer'],
            'destination' => ['nullable', 'string', 'max:150'],
        ]);
        $status = $data['delivery_status'] ?? null;

        if ($status === 'delivered') {
            $document->loadMissing(['lines', 'receipts']);
            $ids = $this->receivableIds($request->user(), $document);
            if (! empty($data['warehouse_id'])) {
                $ids = array_values(array_intersect($ids, [(int) $data['warehouse_id']]));
            }
            // مسودة أو غير صرفية أو مكتملة الاستلام: الخدمة ترفض برسالتها (422)، لا رفض صلاحية.
            $notReceivable = $document->type !== StockDocument::TYPE_TRANSFER
                || $document->status !== StockDocument::STATUS_POSTED || $document->isReceived();
            if ($ids === [] && $notReceivable) {
                return $this->attempt(fn () => $this->success('تم', $this->present($request,
                    $this->documents->confirmReceipt($document, $request->user(), []))));
            }
            if ($ids === []) {
                return $this->error('تأكيد الاستلام لمن له صلاحية على مخزن الوجهة فقط.', 403);
            }

            return $this->attempt(fn () => $this->success('تم تأكيد الاستلام',
                $this->present($request, $this->documents->confirmReceipt($document, $request->user(), $ids))));
        }
        if ($status === 'undelivered' && $document->isReceived()) {
            return $this->error('لا يُلغى تأكيد الاستلام. صحّح المستند بعكسه.', 422);
        }
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($document->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم التحديث', $this->present($request,
            $this->documents->updateDestination($document, array_key_exists('destination', $data) ? $data['destination'] : $document->destination))));
    }

    /** ما يراه المستخدم: المصدر أو الوجهة أو وجهة أي سطر من مخازنه. */
    private function visible(Builder $query, User $user): Builder
    {
        if ($this->access->hasGlobalAccess($user)) {
            return $query;
        }
        $ids = $this->access->assignedIds($user);

        return $query->where(fn ($w) => $w->whereIn('warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids)
            ->orWhereHas('lines', fn ($l) => $l->whereIn('to_warehouse_id', $ids)));
    }

    private function denyUnlessCanSee(Request $request, StockDocument $document): ?JsonResponse
    {
        $user = $request->user();
        $document->loadMissing('lines');
        $ids = array_unique(array_filter([$document->warehouse_id, ...$document->destinationIds()]));
        foreach ($ids as $id) {
            if ($this->access->canAccess($user, (int) $id)) {
                return null;
            }
        }

        return $this->error('لا تملك صلاحية الوصول لهذا المخزن.', 403);
    }

    /**
     * الوجهات التي ينتظر استلامها ويملك المستخدم تأكيدها (مستوى "تعديل" + صلاحية إدارة المستندات).
     *
     * @return array<int>
     */
    private function receivableIds(User $user, StockDocument $doc): array
    {
        if ($doc->type !== StockDocument::TYPE_TRANSFER || $doc->status !== StockDocument::STATUS_POSTED || $doc->isReceived()
            || ! $user->can('inventory.documents.manage')) {
            return [];
        }
        $received = $doc->receipts->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter(
            array_diff($doc->destinationIds(), $received),
            fn ($id) => $this->access->canEdit($user, $id),
        ));
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

        $isTransfer = $doc->type === StockDocument::TYPE_TRANSFER;
        $destinationIds = $isTransfer ? $doc->destinationIds() : [];

        $row = [
            ...$doc->toArray(),
            'type_label' => StockDocument::TYPES[$doc->type]['label'],
            'costs_visible' => $showCosts,
            'receipt_status' => $doc->receiptStatus(),
            'destinations_count' => count($destinationIds),
        ];
        unset($row['receipts']);

        if ($withLines) {
            // ما يظهر من أزرار في عرض المستند يحسبه الخادم (الواجهة لا ترى ربط المستخدم بمخازن الوجهة).
            $posted = $doc->status === StockDocument::STATUS_POSTED;
            $receivable = $this->receivableIds($user, $doc);
            $row['receivable_warehouse_ids'] = $receivable;
            $row['can_receive'] = $receivable !== [];
            if ($isTransfer) {
                $receipts = $doc->receipts->keyBy('warehouse_id');
                $warehouses = Warehouse::whereIn('id', $destinationIds)->get(['id', 'name', 'code'])->keyBy('id');
                $row['destinations'] = array_map(fn ($id) => [
                    'id' => $id,
                    'name' => $warehouses[$id]->name ?? null,
                    'code' => $warehouses[$id]->code ?? null,
                    'received_at' => $receipts->get($id)?->received_at?->toIso8601String(),
                    'received_by' => $receipts->get($id)?->receiver?->name,
                ], $destinationIds);
            }
            $row['purchase_bill'] = $doc->purchase_bill_id
                ? \App\Models\PurchaseBill::whereKey($doc->purchase_bill_id)->first(['id', 'bill_number'])
                : null;
            $row['can_reverse'] = $posted && ! $doc->purchase_bill_id && $user->can('inventory.documents.reverse')
                && (! $doc->hasAnyReceipt() || $user->can(self::REVERSE_RECEIVED));
        } else {
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
