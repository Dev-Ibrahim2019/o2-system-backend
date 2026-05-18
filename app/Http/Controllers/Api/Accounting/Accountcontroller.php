<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\StoreAccountRequest;
use App\Http\Requests\Api\Accounting\UpdateAccountRequest;
use App\Http\Resources\AccountingResources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends ApiController
{
    // ── GET /accounts ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Account::with('parent')
            ->when($request->type,      fn($q) => $q->where('type', $request->type))
            ->when($request->is_active, fn($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->search,    fn($q) => $q->where(
                fn($qb) =>
                $qb->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
            ))
            ->when($request->parent_id, fn($q) => $q->where('parent_id', $request->parent_id))
            ->orderBy('code');

        // شجرة كاملة أو قائمة مسطحة
        if ($request->boolean('tree')) {
            $accounts = Account::with('childrenRecursive')->whereNull('parent_id')->orderBy('code')->get();
        } else {
            $accounts = $query->get();
        }

        return $this->success('Accounts fetched', AccountResource::collection($accounts));
    }

    // ── POST /accounts ────────────────────────────────────────────────────────
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $data = $request->validated();

        // حساب المستوى تلقائياً إذا لم يُرسَل
        if (!isset($data['level']) && isset($data['parent_id'])) {
            $parent       = Account::findOrFail($data['parent_id']);
            $data['level'] = $parent->level + 1;

            // الحساب الأم لا يقبل قيوداً مباشرة — اختياري
            // $parent->update(['allow_posting' => false]);
        }

        $account = Account::create($data);

        return $this->success(
            'تم إنشاء الحساب بنجاح',
            new AccountResource($account->load('parent')),
            201
        );
    }

    // ── GET /accounts/{account} ───────────────────────────────────────────────
    public function show(Request $request, Account $account): JsonResponse
    {
        $account->load(['parent', 'children']);

        return $this->success('Account fetched', new AccountResource($account));
    }

    // ── PUT /accounts/{account} ───────────────────────────────────────────────
    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        // منع تعديل حسابات النظام
        if ($account->is_system) {
            return $this->error('لا يمكن تعديل حسابات النظام', 403);
        }

        $account->update($request->validated());

        return $this->success(
            'تم تحديث الحساب بنجاح',
            new AccountResource($account->fresh()->load('parent'))
        );
    }

    // ── DELETE /accounts/{account} ────────────────────────────────────────────
    public function destroy(Account $account): JsonResponse
    {
        if ($account->is_system) {
            return $this->error('لا يمكن حذف حسابات النظام', 403);
        }

        if ($account->entries()->exists()) {
            return $this->error('لا يمكن حذف حساب له قيود محاسبية', 422);
        }

        if ($account->children()->exists()) {
            return $this->error('لا يمكن حذف حساب له حسابات فرعية', 422);
        }

        $account->delete();

        return $this->success('تم حذف الحساب بنجاح', []);
    }

    // ── GET /accounts/{account}/ledger ────────────────────────────────────────
    // كشف حساب (دفتر الأستاذ)
    public function ledger(Request $request, Account $account): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to',   now()->toDateString());

        $entries = $account->entries()
            ->with(['transaction:id,transaction_number,date,type,description,reference'])
            ->whereHas(
                'transaction',
                fn($q) =>
                $q->where('status', 'posted')
                    ->whereBetween('date', [$from, $to])
            )
            ->orderBy('created_at')
            ->get();

        // الرصيد الافتتاحي (قبل الفترة)
        $openingDebit  = $account->entries()
            ->whereHas(
                'transaction',
                fn($q) =>
                $q->where('status', 'posted')->where('date', '<', $from)
            )->sum('debit');

        $openingCredit = $account->entries()
            ->whereHas(
                'transaction',
                fn($q) =>
                $q->where('status', 'posted')->where('date', '<', $from)
            )->sum('credit');

        $openingBalance = in_array($account->type, ['asset', 'expense'])
            ? $openingDebit - $openingCredit
            : $openingCredit - $openingDebit;

        // بناء الكشف مع الأرصدة المتراكمة
        $runningBalance = $openingBalance;
        $ledgerLines    = [];

        foreach ($entries as $entry) {
            $debit  = (float) $entry->debit;
            $credit = (float) $entry->credit;

            $runningBalance += in_array($account->type, ['asset', 'expense'])
                ? ($debit - $credit)
                : ($credit - $debit);

            $ledgerLines[] = [
                'date'               => $entry->transaction->date->format('Y-m-d'),
                'transaction_number' => $entry->transaction->transaction_number,
                'reference'          => $entry->transaction->reference,
                'description'        => $entry->description ?? $entry->transaction->description,
                'debit'              => $debit,
                'credit'             => $credit,
                'balance'            => round($runningBalance, 3),
            ];
        }

        return $this->success('Account ledger fetched', [
            'account'         => new AccountResource($account),
            'period'          => ['from' => $from, 'to' => $to],
            'opening_balance' => round($openingBalance, 3),
            'total_debit'     => (float) $entries->sum('debit'),
            'total_credit'    => (float) $entries->sum('credit'),
            'closing_balance' => round($runningBalance, 3),
            'lines'           => $ledgerLines,
        ]);
    }
}