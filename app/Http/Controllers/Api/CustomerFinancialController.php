<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\RecordInvoiceRequest;
use App\Http\Requests\Api\Accounting\RecordPaymentRequest;
use App\Models\Customer;
use App\Services\Accounting\CustomerAccountingService;
use App\Services\Accounting\SubledgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerFinancialController extends ApiController
{
    public function __construct(
        private readonly CustomerAccountingService $customerService,
        private readonly SubledgerService $subledgerService,
    ) {}

    /**
     * POST /api/customers/{customer}/invoice
     */
    public function recordInvoice(RecordInvoiceRequest $request, Customer $customer): JsonResponse
    {
        try {
            $transaction = $this->customerService->recordInvoice(
                customer: $customer,
                amount: $request->validated('amount'),
                date: $request->validated('date'),
                reference: $request->validated('reference'),
                branchId: $request->validated('branch_id'),
            );

            return $this->success('تم تسجيل الفاتورة بنجاح', [
                'transaction' => $transaction,
                'balance'     => $this->subledgerService->getCustomerBalance($customer->id),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/customers/{customer}/payment
     */
    public function recordPayment(RecordPaymentRequest $request, Customer $customer): JsonResponse
    {
        try {
            $transaction = $this->customerService->recordPayment(
                customer: $customer,
                amount: $request->validated('amount'),
                cashAccountId: $request->validated('cash_account_id'),
                date: $request->validated('date'),
                reference: $request->validated('reference'),
                branchId: $request->validated('branch_id'),
            );

            return $this->success('تم تسجيل الدفعة بنجاح', [
                'transaction' => $transaction,
                'balance'     => $this->subledgerService->getCustomerBalance($customer->id),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/customers/{customer}/statement
     */
    public function accountStatement(Request $request, Customer $customer): JsonResponse
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   now()->toDateString());

        $statement = $this->subledgerService->getStatement(
            'customer',
            $customer->id,
            '1120',
            $from,
            $to
        );

        return $this->success('كشف الحساب مستخرج بنجاح', $statement);
    }
}
