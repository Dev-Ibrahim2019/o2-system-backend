<?php


namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\StoreTransactionRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Accounting\TransactionPostingService;

class TransactionController extends ApiController
{
    /**
     * ✅ NEW METHOD: جلب الإحصائيات اليومية للقيود المحاسبية
     * 
     * GET /api/transactions/stats/daily?date=2026-05-21
     * 
     * @param Request $request
     * @return JsonResponse
     */

    public function __construct(
        private readonly TransactionPostingService $postingService,
    ) {}

    // ── GET /transactions/by-source ──────────────────────────────────────────
    public function bySource(Request $request): JsonResponse
    {
        $request->validate([
            'source_type' => 'required|string',
            'source_id'   => 'required|integer',
        ]);

        $transactions = Transaction::forSource($request->source_type, $request->source_id)
            ->with(['entries.account:id,name,code,type', 'branch:id,name', 'source'])
            ->orderByDesc('date')
            ->get();

        return $this->success('Transactions fetched', TransactionResource::collection($transactions));
    }
    public function dailyStats(Request $request): JsonResponse
    {
        $date = $request->date ?? now()->toDateString();

        // جلب جميع القيود في هذا اليوم مع الأسطر المرتبطة بها
        $transactions = Transaction::whereDate('date', $date)
            ->with('entries')
            ->get();

        // حساب الإحصائيات
        $stats = $transactions->reduce(function ($carry, $transaction) {
            $debit = $transaction->entries->sum('debit');
            $credit = $transaction->entries->sum('credit');

            $carry['total_lines'] += $transaction->entries->count();
            $carry['total_debit'] += $debit;
            $carry['total_credit'] += $credit;
            $carry['transactions_count'] += 1;

            return $carry;
        }, [
            'total_lines' => 0,
            'total_debit' => 0,
            'total_credit' => 0,
            'transactions_count' => 0,
            'date' => $date,
        ]);

        return $this->success('إحصائيات اليوم تم جلبها بنجاح', $stats);
    }

    /**
     * ✅ MODIFIED METHOD: جلب قائمة القيود مع الإجماليات
     * 
     * GET /api/transactions?page=1&per_page=30
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // جلب القيود مع جميع العلاقات المطلوبة
        $transactions = Transaction::with([
            'branch:id,name',
            'user:id,name',
            'source',
            'entries.account:id,name,code,type',  // ✅ إضافة الحسابات مع النوع
            'entries.costCenter:id,name'            // ✅ إضافة مراكز التكلفة
        ])
            ->when($request->type,      fn($q) => $q->where('type', $request->type))
            ->when($request->status,    fn($q) => $q->where('status', $request->status))
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->from,      fn($q) => $q->where('date', '>=', $request->from))
            ->when($request->to,        fn($q) => $q->where('date', '<=', $request->to))
            ->when($request->reference, fn($q) => $q->where('reference', 'like', "%{$request->reference}%"))
            ->when($request->search,    fn($q) => $q->where(
                fn($qb) =>
                $qb->where('transaction_number', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%")
                    ->orWhere('reference', 'like', "%{$request->search}%")
            ))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 30);

        // ✅ حساب الإجماليات من البيانات المجلوبة
        $items = $transactions->items();
        $totalDebit = collect($items)->sum(fn($t) => $t->entries->sum('debit'));
        $totalCredit = collect($items)->sum(fn($t) => $t->entries->sum('credit'));
        $totalLines = collect($items)->sum(fn($t) => $t->entries->count());

        return $this->success('تم جلب القيود بنجاح', [
            'data'       => TransactionResource::collection($items),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
                'per_page'     => $transactions->perPage(),
            ],
            // ✅ إضافة الإجماليات في الرد
            'totals' => [
                'debit'       => $totalDebit,
                'credit'      => $totalCredit,
                'lines_count' => $totalLines,
                'difference'  => abs($totalDebit - $totalCredit),
            ],
        ]);
    }

    /**
     * ✅ MODIFIED METHOD: جلب تفاصيل قيد معين مع جميع الأسطر والحسابات
     * 
     * GET /api/transactions/{transaction}
     * 
     * @param Transaction $transaction
     * @return JsonResponse
     */
    public function show(Transaction $transaction): JsonResponse
    {
        // ✅ جلب جميع التفاصيل المطلوبة
        $transaction->load([
            'source',
            'entries.account:id,name,code,type',      // الحساب مع النوع
            'entries.costCenter:id,name',               // مركز التكلفة
            'branch:id,name',                           // الفرع
            'user:id,name,email',                       // المستخدم
        ]);

        // ✅ حساب إجماليات الأسطر
        $totalDebit = $transaction->entries->sum('debit');
        $totalCredit = $transaction->entries->sum('credit');

        return $this->success('تم جلب القيد بنجاح', [
            'transaction' => new TransactionResource($transaction),
            'entries_summary' => [
                'total_lines'  => $transaction->entries->count(),
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'balanced'     => $totalDebit == $totalCredit,
            ],
        ]);
    }

    /**
     * ✅ NEW METHOD: جلب إحصائيات شاملة للقيود المحاسبية
     * 
     * GET /api/transactions/stats/comprehensive?from=2026-05-01&to=2026-05-31
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function comprehensiveStats(Request $request): JsonResponse
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $transactions = Transaction::whereBetween('date', [$from, $to])
            ->with('entries')
            ->get();

        $stats = [
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'total_transactions' => $transactions->count(),
                'total_lines' => $transactions->sum(fn($t) => $t->entries->count()),
                'total_debit' => $transactions->sum(fn($t) => $t->entries->sum('debit')),
                'total_credit' => $transactions->sum(fn($t) => $t->entries->sum('credit')),
            ],
            'by_status' => [
                'posted' => $transactions->where('status', 'posted')->count(),
                'draft' => $transactions->where('status', 'draft')->count(),
                'cancelled' => $transactions->where('status', 'cancelled')->count(),
            ],
            'by_type' => $transactions->groupBy('type')->map(fn($group) => [
                'count' => $group->count(),
                'debit' => $group->sum(fn($t) => $t->entries->sum('debit')),
                'credit' => $group->sum(fn($t) => $t->entries->sum('credit')),
            ]),
        ];

        return $this->success('الإحصائيات الشاملة تم جلبها بنجاح', $stats);
    }

    /**
     * ✅ NEW METHOD: جلب تفاصيل الأسطر لقيد معين
     * 
     * GET /api/transactions/{transaction}/entries
     * 
     * @param Transaction $transaction
     * @return JsonResponse
     */
    public function getEntries(Transaction $transaction): JsonResponse
    {
        $transaction->load([
            'entries.account:id,name,code,type',
            'entries.costCenter:id,name',
        ]);

        $entries = $transaction->entries->map(fn($entry) => [
            'id' => $entry->id,
            'account' => [
                'id' => $entry->account->id,
                'name' => $entry->account->name,
                'code' => $entry->account->code,
                'type' => $entry->account->type,
            ],
            'debit' => $entry->debit,
            'credit' => $entry->credit,
            'description' => $entry->description,
            'cost_center' => $entry->costCenter ? [
                'id' => $entry->costCenter->id,
                'name' => $entry->costCenter->name,
            ] : null,
            'sort_order' => $entry->sort_order,
        ]);

        return $this->success('تم جلب الأسطر بنجاح', [
            'transaction_id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'entries' => $entries,
            'totals' => [
                'debit' => $entries->sum('debit'),
                'credit' => $entries->sum('credit'),
                'count' => $entries->count(),
            ],
        ]);
    }

    // ── POST /transactions ────────────────────────────────────────────────────
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $transaction = Transaction::create([
                'transaction_number' => Transaction::generateNumber(),
                'date'               => $data['date'],
                'type'               => $data['type'],
                'status'             => 'draft',
                'reference'          => $data['reference'] ?? null,
                'description'        => $data['description'] ?? null,
                'branch_id'          => $data['branch_id'] ?? null,
                'user_id'            => auth()->id(),

                'notes'              => $data['notes'] ?? null,
            ]);

            foreach ($data['entries'] as $index => $entryData) {
                $transaction->entries()->create([
                    'account_id'     => $entryData['account_id'],
                    'debit'          => $entryData['debit'],
                    'credit'         => $entryData['credit'],
                    'description'    => $entryData['description'] ?? null,
                    'cost_center_id' => $entryData['cost_center_id'] ?? null,
                    'subledger_type' => $entryData['subledger_type'] ?? null,
                    'subledger_id'   => $entryData['subledger_id'] ?? null,
                    'sort_order'     => $entryData['sort_order'] ?? $index,
                ]);
            }

            DB::commit();

            return $this->success(
                'تم إنشاء القيد بنجاح',
                new TransactionResource($transaction->load(['entries.account', 'entries.costCenter', 'branch', 'user'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل إنشاء القيد: ' . $e->getMessage(), 500);
        }
    }



    // ── PUT /transactions/{transaction} ───────────────────────────────────────
    // تعديل مسموح فقط للمسودات (draft)
    public function update(StoreTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        if (!$transaction->isEditable()) {
            return $this->error('لا يمكن تعديل قيد مرحَّل أو ملغي', 422);
        }

        $data = $request->validated();

        DB::beginTransaction();
        try {
            $transaction->update([
                'date'        => $data['date'],
                'type'        => $data['type'],
                'reference'   => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'branch_id'   => $data['branch_id'] ?? null,

                'notes'       => $data['notes'] ?? null,
            ]);

            // حذف السطور القديمة وإعادة إنشائها
            $transaction->entries()->delete();

            foreach ($data['entries'] as $index => $entryData) {
                $transaction->entries()->create([
                    'account_id'     => $entryData['account_id'],
                    'debit'          => $entryData['debit'],
                    'credit'         => $entryData['credit'],
                    'description'    => $entryData['description'] ?? null,
                    'cost_center_id' => $entryData['cost_center_id'] ?? null,
                    'subledger_type' => $entryData['subledger_type'] ?? null,
                    'subledger_id'   => $entryData['subledger_id'] ?? null,
                    'sort_order'     => $entryData['sort_order'] ?? $index,
                ]);
            }

            DB::commit();

            return $this->success(
                'تم تحديث القيد بنجاح',
                new TransactionResource($transaction->fresh()->load(['entries.account', 'entries.costCenter', 'branch', 'user']))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل تحديث القيد: ' . $e->getMessage(), 500);
        }
    }

    // ── DELETE /transactions/{transaction} ────────────────────────────────────
    public function destroy(Transaction $transaction): JsonResponse
    {
        if ($transaction->status === 'posted') {
            return $this->error('لا يمكن حذف قيد مرحَّل — يمكنك إلغاؤه', 422);
        }

        $transaction->entries()->delete();
        $transaction->delete();

        return $this->success('تم حذف القيد بنجاح', []);
    }

    // ── POST /transactions/{transaction}/post ─────────────────────────────────
    // ترحيل القيد (draft → posted) باستخدام TransactionPostingService
    public function post(Transaction $transaction): JsonResponse
    {
        try {
            $posted = $this->postingService->post($transaction);

            return $this->success(
                'تم ترحيل القيد بنجاح',
                new TransactionResource($posted->load(['entries.account', 'entries.costCenter', 'branch', 'user']))
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── POST /transactions/{transaction}/cancel ───────────────────────────────
    // إلغاء القيد
    public function cancel(Transaction $transaction): JsonResponse
    {
        if ($transaction->status === 'cancelled') {
            return $this->error('القيد ملغي مسبقاً', 422);
        }

        $transaction->update(['status' => 'cancelled']);

        return $this->success('تم إلغاء القيد', new TransactionResource($transaction->fresh()));
    }

    // ── POST /transactions/{transaction}/reverse ──────────────────────────────
    // عكس قيد مرحّل (Reversal)
    public function reverse(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'date'   => ['nullable', 'date'],
        ]);

        try {
            $reversal = $this->postingService->reverse(
                transaction: $transaction,
                reason: $data['reason'],
                date: $data['date'] ?? null,
            );

            return $this->success(
                'تم عكس القيد بنجاح',
                new TransactionResource($reversal->load(['entries.account', 'entries.costCenter', 'branch', 'user']))
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
