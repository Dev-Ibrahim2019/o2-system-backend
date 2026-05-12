<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\StoreTransactionRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends ApiController
{
    // ── GET /transactions ─────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::with(['branch:id,name', 'user:id,name'])
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

        return $this->success('Transactions fetched', [
            'data'       => TransactionResource::collection($transactions->items()),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
                'per_page'     => $transactions->perPage(),
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

    // ── GET /transactions/{transaction} ───────────────────────────────────────
    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load([
            'entries.account',
            'entries.costCenter',
            'branch:id,name',
            'user:id,name',
        ]);

        return $this->success('Transaction fetched', new TransactionResource($transaction));
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
    // ترحيل القيد (draft → posted)
    public function post(Transaction $transaction): JsonResponse
    {
        if ($transaction->status !== 'draft') {
            return $this->error('يمكن ترحيل المسودات فقط', 422);
        }

        if (!$transaction->isBalanced()) {
            return $this->error('لا يمكن ترحيل قيد غير متوازن', 422);
        }

        $transaction->post();

        return $this->success(
            'تم ترحيل القيد بنجاح',
            new TransactionResource($transaction->fresh()->load(['entries.account', 'branch']))
        );
    }

    // ── POST /transactions/{transaction}/cancel ───────────────────────────────
    // إلغاء القيد
    public function cancel(Transaction $transaction): JsonResponse
    {
        if ($transaction->status === 'cancelled') {
            return $this->error('القيد ملغي مسبقاً', 422);
        }

        $transaction->cancel();

        return $this->success('تم إلغاء القيد', new TransactionResource($transaction->fresh()));
    }
}
