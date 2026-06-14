<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Supplier;
use App\Services\Accounting\SupplierAccountingService;
use App\Services\Accounting\SubledgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierFinancialController extends ApiController
{
    public function __construct(
        private readonly SupplierAccountingService $supplierService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // SUPPLIER CRUD
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/suppliers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->with('branch:id,name');

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Sorting
        $sortField = $request->input('sort_by', 'name');
        $sortDir   = $request->input('sort_dir', 'asc');
        $query->orderBy($sortField, $sortDir);

        $perPage = $request->input('per_page', 20);
        $suppliers = $query->paginate($perPage);

        // إضافة الرصيد لكل مورد
        $suppliers->getCollection()->transform(function ($supplier) {
            $supplier->balance = $supplier->balance;
            return $supplier;
        });

        return $this->success('تم جلب الموردين', $suppliers);
    }

    /**
     * POST /api/suppliers
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'name_en'       => ['nullable', 'string', 'max:255'],
            'code'          => ['nullable', 'string', 'max:50', 'unique:suppliers,code'],
            'tax_number'    => ['nullable', 'string', 'max:50'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'mobile'        => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'email', 'max:255'],
            'address'       => ['nullable', 'string', 'max:500'],
            'city'          => ['nullable', 'string', 'max:100'],
            'category'      => ['nullable', 'string', 'in:local,international,service'],
            'currency'      => ['nullable', 'string', 'max:3'],
            'status'        => ['nullable', 'string', 'in:active,inactive,blocked'],
            'credit_limit'  => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'in:immediate,net15,net30,net60,net90'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'gps_link'      => ['nullable', 'string', 'max:500'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        // Auto-generate code if not provided
        if (empty($data['code'])) {
            $lastId = Supplier::withTrashed()->max('id') ?? 0;
            $data['code'] = 'SUP-' . str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);
        }

        $data['status'] ??= 'active';
        $data['currency'] ??= 'ILS';

        $supplier = Supplier::create($data);

        // Post opening balance if set
        if (($data['opening_balance'] ?? 0) > 0) {
            $openingAccount = \App\Models\Account::where('code', '3999')->first();
            if ($openingAccount) {
                $this->supplierService->postOpeningBalance(
                    supplier: $supplier,
                    amount: (float) $data['opening_balance'],
                    openingBalanceAccountId: $openingAccount->id,
                    date: now()->toDateString(),
                    branchId: $data['branch_id'] ?? null,
                );
            }
        }

        $supplier->load('branch:id,name');

        return $this->success('تم إنشاء المورد بنجاح', $supplier, 201);
    }

    /**
     * GET /api/suppliers/{supplier}
     */
    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load('branch:id,name');
        $supplier->balance = $supplier->balance;

        $aging = $this->supplierService->getAging($supplier);

        return $this->success('تفاصيل المورد', [
            'supplier' => $supplier,
            'aging'    => $aging,
            'statement_summary' => $this->supplierService->getStatement(
                $supplier,
                now()->startOfMonth()->toDateString(),
                now()->toDateString(),
            ),
        ]);
    }

    /**
     * PUT /api/suppliers/{supplier}
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'name_en'       => ['nullable', 'string', 'max:255'],
            'code'          => ['nullable', 'string', 'max:50', 'unique:suppliers,code,' . $supplier->id],
            'tax_number'    => ['nullable', 'string', 'max:50'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'mobile'        => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'email', 'max:255'],
            'address'       => ['nullable', 'string', 'max:500'],
            'city'          => ['nullable', 'string', 'max:100'],
            'category'      => ['nullable', 'string', 'in:local,international,service'],
            'currency'      => ['nullable', 'string', 'max:3'],
            'status'        => ['nullable', 'string', 'in:active,inactive,blocked'],
            'credit_limit'  => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'in:immediate,net15,net30,net60,net90'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'gps_link'      => ['nullable', 'string', 'max:500'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $supplier->update($data);
        $supplier->load('branch:id,name');
        $supplier->balance = $supplier->balance;

        return $this->success('تم تحديث المورد', $supplier);
    }

    /**
     * DELETE /api/suppliers/{supplier}
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();
        return $this->success('تم حذف المورد');
    }

    // ──────────────────────────────────────────────────────────
    // ACCOUNTING OPERATIONS
    // ──────────────────────────────────────────────────────────

    /**
     * POST /api/suppliers/{supplier}/bill
     */
    public function recordBill(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount'            => ['required', 'numeric', 'min:0.001'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'              => ['required', 'date'],
            'reference'         => ['nullable', 'string', 'max:100'],
            'branch_id'         => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->supplierService->recordBill(
            supplier: $supplier,
            amount: $data['amount'],
            expenseAccountId: $data['expense_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل فاتورة المورد', [
            'transaction' => $transaction,
            'balance'     => $supplier->balance,
        ], 201);
    }

    /**
     * POST /api/suppliers/{supplier}/payment
     */
    public function recordPayment(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'reference'       => ['nullable', 'string', 'max:100'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->supplierService->recordPayment(
            supplier: $supplier,
            amount: $data['amount'],
            cashAccountId: $data['cash_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل الدفعة', [
            'transaction' => $transaction,
            'balance'     => $supplier->balance,
        ]);
    }

    /**
     * POST /api/suppliers/{supplier}/credit-note
     */
    public function recordCreditNote(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount'             => ['required', 'numeric', 'min:0.001'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'               => ['required', 'date'],
            'reference'          => ['nullable', 'string', 'max:100'],
            'branch_id'          => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->supplierService->recordCreditNote(
            supplier: $supplier,
            amount: $data['amount'],
            expenseAccountId: $data['expense_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل إشعار دائن', [
            'transaction' => $transaction,
            'balance'     => $supplier->balance,
        ]);
    }

    /**
     * POST /api/suppliers/{supplier}/debit-note
     */
    public function recordDebitNote(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount'             => ['required', 'numeric', 'min:0.001'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'               => ['required', 'date'],
            'reference'          => ['nullable', 'string', 'max:100'],
            'branch_id'          => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->supplierService->recordDebitNote(
            supplier: $supplier,
            amount: $data['amount'],
            expenseAccountId: $data['expense_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل إشعار مدين', [
            'transaction' => $transaction,
            'balance'     => $supplier->balance,
        ]);
    }

    /**
     * GET /api/suppliers/{supplier}/statement
     */
    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        $statement = $this->supplierService->getStatement(
            supplier: $supplier,
            from: $from,
            to: $to,
            branchId: $request->input('branch_id'),
        );

        return $this->success('كشف حساب المورد', [
            'supplier'   => ['id' => $supplier->id, 'name' => $supplier->name, 'code' => $supplier->code],
            'balance'    => $supplier->balance,
            'period'     => ['from' => $from, 'to' => $to],
            'statement'  => $statement,
        ]);
    }

    /**
     * GET /api/suppliers/{supplier}/aging
     */
    public function aging(Supplier $supplier): JsonResponse
    {
        $aging = $this->supplierService->getAging($supplier);

        return $this->success('تحليل أعمار المورد', [
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name],
            'aging'    => $aging,
        ]);
    }

    /**
     * GET /api/suppliers/{supplier}/monthly-payments
     * إجمالي المدفوعات المدفوعة لهذا المورد في الشهر الحالي
     */
    public function monthlyPayments(Supplier $supplier): JsonResponse
    {
        $payments = $this->supplierService->getMonthlyPayments($supplier);

        return $this->success('إجمالي المدفوعات الشهرية', [
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name],
            'payments' => $payments,
        ]);
    }

    /**
     * GET /api/suppliers/aging-report
     * تقرير أعمار جميع الموردين
     */
    public function agingReport(Request $request): JsonResponse
    {
        $suppliers = Supplier::where('status', 'active')->get();
        $report = [];

        foreach ($suppliers as $supplier) {
            $aging = $this->supplierService->getAging($supplier);
            if ($aging['total'] > 0) {
                $report[] = [
                    'id'       => $supplier->id,
                    'name'     => $supplier->name,
                    'code'     => $supplier->code,
                    'balance'  => $supplier->balance,
                    'aging'    => $aging,
                ];
            }
        }

        // إجماليات
        $totals = [
            'current' => collect($report)->sum('aging.current'),
            '1_30'    => collect($report)->sum('aging.1_30'),
            '31_60'   => collect($report)->sum('aging.31_60'),
            '61_90'   => collect($report)->sum('aging.61_90'),
            'over_90' => collect($report)->sum('aging.over_90'),
            'total'   => collect($report)->sum('aging.total'),
        ];

        return $this->success('تقرير أعمار الموردين', [
            'suppliers' => $report,
            'totals'    => $totals,
        ]);
    }

    /**
     * GET /api/suppliers/{supplier}/transactions
     */
    public function transactions(Supplier $supplier): JsonResponse
    {
        $transactions = $supplier->transactions()
            ->with(['entries.account:id,name,code', 'branch:id,name'])
            ->orderByDesc('date')
            ->paginate(20);

        return $this->success('معاملات المورد', $transactions);
    }
}
