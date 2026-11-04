<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Account;
use App\Models\InventorySetting;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\PurchaseInvoiceService;
use App\Services\Inventory\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * فاتورة المشتريات الموحّدة (/api/inventory/purchases): مسودة ← مرحّلة (غير مدفوعة / جزئية / مدفوعة) ← معكوسة.
 * المستخدم يرى فواتير مخازنه فقط (WarehouseAccess)؛ الفواتير القديمة بلا مخزن تظهر لمن له كل المخازن.
 */
class PurchaseInvoiceController extends InventoryController
{
    public function __construct(private PurchaseInvoiceService $purchases, private WarehouseAccess $access) {}

    /** GET purchases/meta — الموردون والمخازن والحسابات ونسب الضريبة وإعداد تحذير السعر. */
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success('تم', [
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'code', 'payment_terms', 'tax_number', 'currency']),
            'warehouses' => $this->access->scope(Warehouse::query(), $user)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code', 'branch_id']),
            // الصندوق والبنوك: حسابات النقد (1110...) التي تقبل القيود
            'cash_accounts' => Account::where('code', 'like', '1110%')->where('allow_posting', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'expense_accounts' => Account::where('type', 'expense')->where('allow_posting', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'tax_rates' => [0, 5, 16],
            'currencies' => ['ILS', 'USD', 'JOD', 'EUR'],
            'price_alert_percent' => (string) InventorySetting::current()->price_alert_percent,
            'today' => AccountingDay::fromConfig()->of(now()),
        ]);
    }

    /**
     * GET purchases?search=&supplier_id=&warehouse_id=&status=&from=&to=&page=
     * status: draft | unpaid | partial | overdue | paid | reversed. مع بطاقات الملخّص ومجموع الفترة (بلا المسودات والمعكوسة).
     */
    public function index(Request $request): JsonResponse
    {
        $today = AccountingDay::fromConfig()->of(now());
        $search = trim((string) $request->query('search', ''));
        $base = $this->scoped($request);

        $filtered = (clone $base)
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('bill_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('bill_date', '<=', $request->query('to')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('bill_number', 'like', "%{$search}%")
                ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))));

        $list = (clone $filtered)
            ->when($request->filled('status'), fn ($q) => $this->statusFilter($q, (string) $request->query('status'), $today))
            ->with(['supplier:id,name,code', 'warehouse:id,name', 'creator:id,name'])
            ->withCount(['items as items_count' => fn ($q) => $q->where('line_kind', 'stock')])
            ->orderByDesc('bill_date')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $list->getCollection()->transform(fn (PurchaseBill $b) => $this->row($b, $today));

        $sum = fn (Builder $q) => $q->whereIn('status', PurchaseBill::POSTED_STATUSES)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(total - paid_amount),0) as remaining')
            ->first();
        $period = $sum((clone $filtered)->when($request->filled('status') && $request->query('status') !== 'reversed',
            fn ($q) => $this->statusFilter($q, (string) $request->query('status'), $today)));

        $monthStart = CarbonImmutable::parse($today)->startOfMonth()->toDateString();
        $in7 = CarbonImmutable::parse($today)->addDays(7)->toDateString();
        $open = fn () => (clone $base)->whereIn('status', ['unpaid', 'partially_paid', 'overdue']);

        return $this->success('تم', [
            'list' => $list,
            'summary' => [
                'count' => (int) $period->count,
                'total' => round((float) $period->total, 2),
                'paid' => round((float) $period->paid, 2),
                'remaining' => round((float) $period->remaining, 2),
            ],
            'cards' => [
                'month_purchases' => round((float) (clone $base)->whereIn('status', PurchaseBill::POSTED_STATUSES)
                    ->whereBetween('bill_date', [$monthStart, $today])->sum('total'), 2),
                'payable' => round((float) $open()->sum(DB::raw('total - paid_amount')), 2),
                'drafts' => (clone $base)->where('status', 'draft')->where('is_inventory', true)->count(),
                'due_soon' => $open()->whereBetween('due_date', [$today, $in7])->count(),
                'overdue' => $open()->where('due_date', '<', $today)->count(),
            ],
        ]);
    }

    /** GET purchases/{bill} */
    public function show(Request $request, PurchaseBill $bill): JsonResponse
    {
        if ($denied = $this->denyUnlessCanSee($request, $bill)) {
            return $denied;
        }

        return $this->success('تم', $this->present($request, $this->purchases->fresh($bill)));
    }

    /** POST purchases — مسودة جديدة. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules());
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($data['warehouse_id']))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم حفظ المسودة',
            $this->present($request, $this->purchases->save(null, $data, $request->user())), 201));
    }

    /** PUT purchases/{bill} — المسودة فقط. */
    public function update(Request $request, PurchaseBill $bill): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules());
        foreach (array_filter([$bill->warehouse_id, $data['warehouse_id']]) as $wh) {
            if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($wh))) {
                return $denied;
            }
        }

        return $this->attempt(fn () => $this->success('تم حفظ المسودة',
            $this->present($request, $this->purchases->save($bill, $data, $request->user()))));
    }

    /** DELETE purchases/{bill} — المسودة فقط. */
    public function destroy(Request $request, PurchaseBill $bill): JsonResponse
    {
        if ($denied = $this->denyWrite($request, $bill)) {
            return $denied;
        }

        return $this->attempt(function () use ($bill) {
            $this->purchases->delete($bill);

            return $this->success('تم حذف المسودة');
        });
    }

    /** POST purchases/{bill}/post */
    public function post(Request $request, PurchaseBill $bill): JsonResponse
    {
        if ($denied = $this->denyWrite($request, $bill)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم ترحيل الفاتورة',
            $this->present($request, $this->purchases->post($bill, $request->user()))));
    }

    /** POST purchases/{bill}/reverse {reason} */
    public function reverse(Request $request, PurchaseBill $bill): JsonResponse
    {
        $data = $this->validateAr($request, ['reason' => ['required', 'string', 'max:500']]);
        if ($denied = $this->denyWrite($request, $bill)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم عكس الفاتورة',
            $this->present($request, $this->purchases->reverse($bill, $data['reason'], $request->user()))));
    }

    /** POST purchases/{bill}/pay {amount, cash_account_id, date?} — تسديد بسند صرف مربوط بالفاتورة. */
    public function pay(Request $request, PurchaseBill $bill): JsonResponse
    {
        $data = $this->validateAr($request, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($denied = $this->denyWrite($request, $bill)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم تسجيل التسديد', $this->present($request,
            $this->purchases->pay($bill, (string) $data['amount'], (int) $data['cash_account_id'], $data['date'] ?? null, $request->user()))));
    }

    /** POST purchases/{bill}/duplicate */
    public function duplicate(Request $request, PurchaseBill $bill): JsonResponse
    {
        if ($denied = $this->denyWrite($request, $bill)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم إنشاء مسودة مكرّرة',
            $this->present($request, $this->purchases->duplicate($bill, $request->user())), 201));
    }

    /** GET purchases/supplier-summary/{supplier} — الرصيد الحالي وآخر فاتورة وأيام الاستحقاق. */
    public function supplierSummary(Supplier $supplier): JsonResponse
    {
        return $this->success('تم', $this->purchases->supplierSummary($supplier));
    }

    /** GET purchases/last-prices?supplier_id=&item_ids[]= — آخر سعر للمورّد نفسه بالشيكل للوحدة الأساسية. */
    public function lastPrices(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'item_ids' => ['required', 'array', 'max:300'],
            'item_ids.*' => ['integer'],
        ]);

        return $this->success('تم', (object) $this->purchases->lastPrices((int) $data['supplier_id'], array_map('intval', $data['item_ids'])));
    }

    /** GET purchases/check-invoice-no?supplier_id=&no=&except= — فواتير بالرقم نفسه للمورّد نفسه (تحذير لا منع). */
    public function checkInvoiceNo(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'supplier_id' => ['required', 'integer'],
            'no' => ['required', 'string', 'max:64'],
            'except' => ['nullable', 'integer'],
        ]);

        return $this->success('تم', PurchaseBill::where('supplier_id', $data['supplier_id'])
            ->where('supplier_invoice_no', $data['no'])->where('status', '!=', 'reversed')
            ->when($data['except'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get(['id', 'bill_number', 'bill_date', 'status']));
    }

    // ── داخلي ─────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'bill_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_mode' => ['required', Rule::in(['cash', 'credit', 'partial'])],
            'currency' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_recoverable' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid_on_post' => ['nullable', 'numeric', 'min:0'],
            'cash_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.kind' => ['required', Rule::in(['stock', 'expense'])],
            'lines.*.stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return Builder<PurchaseBill> */
    private function scoped(Request $request): Builder
    {
        $user = $request->user();

        return PurchaseBill::query()->when(! $this->access->hasGlobalAccess($user),
            fn ($q) => $q->whereIn('warehouse_id', $this->access->assignedIds($user)));
    }

    /** @param  Builder<PurchaseBill>  $q */
    private function statusFilter(Builder $q, string $status, string $today): Builder
    {
        return match ($status) {
            'draft' => $q->where('status', 'draft'),
            'unpaid' => $q->where('status', 'unpaid')->where('due_date', '>=', $today),
            'partial' => $q->where('status', 'partially_paid'),
            'overdue' => $q->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])->where('due_date', '<', $today),
            'paid' => $q->where('status', 'paid'),
            'reversed' => $q->where('status', 'reversed'),
            default => $q,
        };
    }

    /** الحالة المعروضة: "متأخرة" تُحسب من الاستحقاق والمتبقي. */
    private static function effectiveStatus(PurchaseBill $b, string $today): string
    {
        $open = in_array($b->status, ['unpaid', 'partially_paid', 'overdue'], true);

        return $open && $b->due_date && $b->due_date->toDateString() < $today ? 'overdue' : ($b->status === 'overdue' ? 'unpaid' : $b->status);
    }

    /** @return array<string, mixed> */
    private function row(PurchaseBill $b, string $today): array
    {
        return [
            ...$b->toArray(),
            'effective_status' => self::effectiveStatus($b, $today),
            'remaining' => round((float) $b->total - (float) $b->paid_amount, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Request $request, PurchaseBill $bill): array
    {
        $user = $request->user();
        $today = AccountingDay::fromConfig()->of(now());
        $ids = $this->scoped($request)->where('is_inventory', true);

        return [
            ...$this->row($bill, $today),
            'prev_id' => (clone $ids)->where('id', '<', $bill->id)->max('id'),
            'next_id' => (clone $ids)->where('id', '>', $bill->id)->min('id'),
            'can' => [
                'edit' => $bill->is_inventory && $bill->status === 'draft' && $user->can('purchases.create'),
                'post' => $bill->is_inventory && $bill->status === 'draft' && $user->can('purchases.post'),
                'reverse' => $bill->is_inventory && $bill->isPosted() && $user->can('purchases.reverse'),
                'pay' => $bill->is_inventory && in_array($bill->status, ['unpaid', 'partially_paid', 'overdue'], true) && $user->can('purchases.pay'),
            ],
        ];
    }

    private function denyUnlessCanSee(Request $request, PurchaseBill $bill): ?JsonResponse
    {
        if ($bill->warehouse_id) {
            return $this->access->canAccess($request->user(), $bill->warehouse_id) ? null : $this->error('لا تملك صلاحية الوصول لهذا المخزن.', 403);
        }

        return $this->access->hasGlobalAccess($request->user()) ? null : $this->error('لا تملك صلاحية الوصول لهذه الفاتورة.', 403);
    }

    private function denyWrite(Request $request, PurchaseBill $bill): ?JsonResponse
    {
        if (! $bill->warehouse_id) {
            return $this->error('هذه فاتورة قديمة (قبل التوحيد) — للقراءة فقط.', 422);
        }

        return $this->denyUnlessCanAccess($request, Warehouse::findOrFail($bill->warehouse_id));
    }
}
