<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\RecordInvoiceRequest;
use App\Http\Requests\Api\Accounting\RecordPaymentRequest;
use App\Models\Supplier;
use App\Services\Accounting\SupplierAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierFinancialController extends ApiController
{
    public function __construct(
        private readonly SupplierAccountingService $supplierService,
    ) {}

    /**
     * POST /api/suppliers/{supplier}/bill
     */
    public function recordBill(RecordInvoiceRequest $request, Supplier $supplier): JsonResponse
    {
        $transaction = $this->supplierService->recordBill(
            supplier: $supplier,
            amount: $request->validated('amount'),
            expenseAccountId: $request->validated('offset_account_id'),
            date: $request->validated('date'),
            reference: $request->validated('reference'),
            branchId: $request->validated('branch_id'),
        );

        return $this->success('تم تسجيل فاتورة المورد بنجاح', $transaction);
    }

    /**
     * POST /api/suppliers/{supplier}/payment
     */
    public function recordPayment(RecordPaymentRequest $request, Supplier $supplier): JsonResponse
    {
        $transaction = $this->supplierService->recordPayment(
            supplier: $supplier,
            amount: $request->validated('amount'),
            cashAccountId: $request->validated('cash_account_id'),
            date: $request->validated('date'),
            reference: $request->validated('reference'),
            branchId: $request->validated('branch_id'),
        );

        return $this->success('تم تسجيل الدفعة للمورد بنجاح', $transaction);
    }

    /**
     * GET /api/suppliers/{supplier}/statement
     */
    public function accountStatement(Request $request, Supplier $supplier): JsonResponse
    {
        if (!$supplier->account_id) {
            return $this->error('المورد لا يمتلك حساباً محاسبياً', 422);
        }

        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to', now()->toDateString());

        $statement = $supplier->account->getStatement($from, $to);

        return $this->success('كشف الحساب مستخرج بنجاح', $statement);
    }
}
