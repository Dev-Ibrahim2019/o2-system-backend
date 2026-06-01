<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\RecordInvoiceRequest;
use App\Http\Requests\Api\Accounting\RecordPaymentRequest;
use App\Models\Customer;
use App\Services\Accounting\CustomerAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerFinancialController extends ApiController
{
    public function __construct(
        private readonly CustomerAccountingService $customerService,
    ) {}

    /**
     * POST /api/customers/{customer}/invoice
     */
    public function recordInvoice(RecordInvoiceRequest $request, Customer $customer): JsonResponse
    {
        $transaction = $this->customerService->recordInvoice(
            customer: $customer,
            amount: $request->validated('amount'),
            revenueAccountId: $request->validated('offset_account_id'),
            date: $request->validated('date'),
            reference: $request->validated('reference'),
            branchId: $request->validated('branch_id'),
        );

        return $this->success('تم تسجيل الفاتورة بنجاح', $transaction);
    }

    /**
     * POST /api/customers/{customer}/payment
     */
    public function recordPayment(RecordPaymentRequest $request, Customer $customer): JsonResponse
    {
        $transaction = $this->customerService->recordPayment(
            customer: $customer,
            amount: $request->validated('amount'),
            cashAccountId: $request->validated('cash_account_id'),
            date: $request->validated('date'),
            reference: $request->validated('reference'),
            branchId: $request->validated('branch_id'),
        );

        return $this->success('تم تسجيل الدفعة بنجاح', $transaction);
    }

    /**
     * GET /api/customers/{customer}/statement
     */
    public function accountStatement(Request $request, Customer $customer): JsonResponse
    {
        if (!$customer->account_id) {
            return $this->error('العميل لا يمتلك حساباً محاسبياً', 422);
        }

        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to', now()->toDateString());

        $statement = $customer->account->getStatement($from, $to);

        return $this->success('كشف الحساب مستخرج بنجاح', $statement);
    }
}
