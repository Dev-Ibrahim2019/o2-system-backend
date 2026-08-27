<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\Entry;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Payment;
use App\Services\Accounting\EmployeeAccountingService;
use App\Services\Accounting\EmployeeModuleService;
use App\Services\Accounting\EmployeeStatementService;
use App\Services\Accounting\StatementClassifier;
use App\Services\Accounting\StatementExportService;
use App\Services\Accounting\SubledgerService;
use Mpdf\Mpdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeFinancialController extends ApiController
{
    public function __construct(
        private readonly EmployeeAccountingService $employeeService,
        private readonly SubledgerService $subledgerService,
        private readonly EmployeeStatementService $statementService,
        private readonly StatementExportService $exportService,
        private readonly EmployeeModuleService $moduleService,
    ) {}

    /**
     * POST /api/employees/{employee}/advance
     */
    public function recordAdvance(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvance(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? $request->user()?->branch_id,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل السلفة بنجاح', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/advance-repayment
     */
    public function recordAdvanceRepayment(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvanceRepayment(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل سداد السلفة', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/salary-accrual
     */
    public function accrualSalary(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.001'],
            'date'        => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->accrualSalary(
                employee: $employee,
                amount: $data['amount'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل استحقاق الراتب', [
                'transaction'    => $transaction,
                'accrued_salary' => $balances['accrued_salary'],
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/salary-payment
     */
    public function paySalary(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'gross_amount'      => ['required', 'numeric', 'min:0.001'],
            'cash_account_id'   => ['required', 'integer', 'exists:accounts,id'],
            'date'              => ['required', 'date'],
            'advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string'],
            'branch_id'         => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->paySalary(
                employee: $employee,
                grossAmount: $data['gross_amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                advanceDeduction: $data['advance_deduction'] ?? 0,
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم دفع الراتب بنجاح', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
                'accrued_salary'      => $balances['accrued_salary'],
                'net_payable'         => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/loan
     */
    public function recordLoan(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'repayment_date'  => ['nullable', 'date', 'after_or_equal:date'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $result = DB::transaction(function () use ($data, $employee, $request) {
                $transaction = $this->employeeService->recordLoan(
                    employee: $employee,
                    amount: $data['amount'],
                    cashAccountId: $data['cash_account_id'],
                    date: $data['date'],
                    description: $data['description'] ?? null,
                    branchId: $data['branch_id'] ?? $request->user()?->branch_id,
                );

                $loan = EmployeeLoan::create([
                    'employee_id'   => $employee->id,
                    'amount'        => $data['amount'],
                    'date_granted'  => $data['date'],
                    'repayment_date'=> $data['repayment_date'] ?? null,
                    'amount_paid'   => 0,
                    'status'        => 'pending',
                    'notes'         => $data['description'] ?? null,
                    'transaction_id'=> $transaction->id,
                ]);

                return [$transaction, $loan];
            });

            [$transaction, $loan] = $result;

            return $this->success('تم تسجيل القرض بنجاح', [
                'transaction'      => $transaction,
                'loan'             => $loan->fresh(),
                'outstanding_loan' => $this->subledgerService->getBalance('employee', $employee->id, '2130'),
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/loan-repayment
     */
    public function recordLoanRepayment(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = DB::transaction(function () use ($data, $employee) {
                $transaction = $this->employeeService->recordLoanRepayment(
                    employee: $employee,
                    amount: $data['amount'],
                    cashAccountId: $data['cash_account_id'],
                    date: $data['date'],
                    description: $data['description'] ?? null,
                    branchId: $data['branch_id'] ?? null,
                );

                $this->applyLoanRepayment($employee, (float) $data['amount'], $data['date']);

                return $transaction;
            });

            return $this->success('تم تسجيل سداد القرض', [
                'transaction'      => $transaction,
                'outstanding_loan' => $this->subledgerService->getBalance('employee', $employee->id, '2130'),
                'loans'            => $employee->loans()->orderByDesc('date_granted')->get(),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Allocate a repayment over the oldest open loans (FIFO).
     */
    private function applyLoanRepayment(Employee $employee, float $amount, string $date): void
    {
        $remaining = $amount;

        $loans = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'partially_repaid'])
            ->orderBy('date_granted')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($loans as $loan) {
            if ($remaining <= 0) {
                break;
            }

            $openAmount = max(0, (float) $loan->amount - (float) $loan->amount_paid);
            if ($openAmount <= 0) {
                continue;
            }

            $applied = min($remaining, $openAmount);
            $newPaid = (float) $loan->amount_paid + $applied;
            $isRepaid = $newPaid + 0.00001 >= (float) $loan->amount;

            $loan->update([
                'amount_paid'    => $newPaid,
                'status'         => $isRepaid ? 'repaid' : 'partially_repaid',
                'repayment_date' => $isRepaid ? $date : $loan->repayment_date,
            ]);

            $remaining -= $applied;
        }
    }

    /**
     * GET /api/employees/{employee}/loans
     */
    public function getLoans(Request $request, Employee $employee): JsonResponse
    {
        $loans = $employee->loans()->orderByDesc('date_granted')->get();

        return $this->success('تم جلب القروض', [
            'loans' => $loans,
        ]);
    }

    // ──────────────────────────────────────────────────────────
    // 7. Batch employee financial data (for directory)
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/financial-batch
     * جلب البيانات المالية لجميع الموظفين دفعة واحدة
     */
    public function financialBatch(Request $request): JsonResponse
    {
        $data = $this->moduleService->financialBatch($request->all());

        return $this->success('البيانات المالية للموظفين', $data);
    }

    // ──────────────────────────────────────────────────────────
    // 8. تسوية مالية (Settlement)
    // ──────────────────────────────────────────────────────────

    /**
     * POST /api/employees/{employee}/settlement
     */
    public function recordSettlement(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'type'            => ['required', 'string', 'in:debit,credit'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordSettlement(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                type: $data['type'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            return $this->success('تم تسجيل التسوية بنجاح', [
                'transaction' => $transaction,
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ──────────────────────────────────────────────────────────
    // 9. Dashboard / إحصائيات عامة
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/dashboard
     * لوحة الموظفين — إحصائيات سريعة
     */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->success('إحصائيات لوحة الموظفين', $this->moduleService->dashboard($request->all()));
    }

    // ──────────────────────────────────────────────────────────
    // 10. Analytics / تحليلات
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/analytics
     * تحليلات الموظفين — حسب القسم
     */
    public function analytics(Request $request): JsonResponse
    {
        return $this->success('تحليلات الموظفين', $this->moduleService->analytics($request->all()));
    }

    /**
     * GET /api/employees/{employee}/financial-summary
     */
    public function financialSummary(Employee $employee): JsonResponse
    {
        return $this->success('ملخص مالي للموظف', $this->moduleService->employeeSummary($employee));
    }

    /**
     * GET /api/employees/{employee}/account-statement
     * كشف حساب الموظف (سلف + رواتب + قروض + فواتير مبيعات + دفعات + قيود)
     *
     * ═══════════════════════════════════════════════════
     * محرك التصنيف الموحد — StatementClassifier
     * ═══════════════════════════════════════════════════
     * - جميع الحسابات وجميع المصادر تُجلب دائماً
     * - التصنيف عبر StatementClassifier::classifyLine()
     *   (لا يعتمد على account_id/account_name إطلاقاً)
     * - الفلترة حسب movement_type تتم بعد التصنيف
     * ═══════════════════════════════════════════════════
     */
    public function accountStatement(Request $request, Employee $employee): JsonResponse
    {
        $filters = $request->validate([
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date'],
            'type'           => ['nullable', 'string'],
            'mode'           => ['nullable', 'in:simple,detailed'],
            'branch_id'      => ['nullable', 'integer'],
            'search'         => ['nullable', 'string', 'max:200'],
            'document_type'  => ['nullable', 'string', 'max:100'],
            'status'         => ['nullable', 'string', 'max:50'],
            'amount_from'    => ['nullable', 'numeric', 'min:0'],
            'amount_to'      => ['nullable', 'numeric', 'min:0'],
            'has_discounts'  => ['nullable'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'order_number'   => ['nullable', 'string', 'max:100'],
            'journal_number' => ['nullable', 'string', 'max:100'],
            'cursor'         => ['nullable'],
            'limit'          => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $filters['from'] = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $filters['to']   = $filters['to']   ?? now()->toDateString();
        $filters['type'] = $filters['type'] ?? 'all';
        $filters['mode'] = $filters['mode'] ?? 'detailed';

        if (!in_array($filters['type'], StatementClassifier::getAllowedFilters(), true)) {
            $filters['type'] = 'all';
        }

        $data = $this->statementService->build($employee, $filters);

        return $this->success('كشف حساب الموظف', $data);
    }

    /**
     * GET /api/employees/{employee}/account-statement/export
     */
    public function accountStatementExport(Request $request, Employee $employee)
    {
        $format = $request->get('format', 'csv');
        $filters = $request->all();
        $filters['mode'] = $request->get('mode', 'detailed');
        $filters['limit'] = 100000;

        $data = $this->statementService->build($employee, $filters);
        $from = $data['period']['from'];
        $to   = $data['period']['to'];
        $base = "كشف_حساب_{$employee->name}_{$from}_{$to}";

        if ($format === 'excel') {
            return $this->exportService->exportExcelXml($data, "{$base}.xls");
        }

        return $this->exportService->exportCsv($data, "{$base}.csv");
    }

    /**
     * GET /api/employees/{employee}/account-statement/pdf
     */
    public function accountStatementPdf(Request $request, Employee $employee)
    {
        $request->validate([
            'from'     => 'nullable|date',
            'to'       => 'nullable|date|after_or_equal:from',
            'type'     => 'nullable|string',
            'pdf_style' => 'nullable|in:simple,detailed',
            'mode'     => 'nullable|in:simple,detailed',
        ]);

        $from = $request->get('from', now()->startOfMonth()->format('Y-m-d'));
        $to   = $request->get('to', now()->format('Y-m-d'));
        $type = $request->get('type', 'all');
        $pdfStyle = $request->get('pdf_style', 'detailed');

        $typeLabels = [
            'all'        => 'الكل',
            'advance'    => 'السلف',
            'salary'     => 'الرواتب',
            'loan'       => 'القروض',
            'sales'      => 'المبيعات',
            'payment'    => 'الدفعات',
            'journal'    => 'القيود',
            'return'     => 'المرتجعات',
            'purchase'   => 'المشتريات',
            'transfer'   => 'التحويلات',
            'adjustment' => 'التسويات',
            'opening'    => 'الرصيد الافتتاحي',
            'closing'    => 'الرصيد الختامي',
        ];

        $statementTypeLabel = $typeLabels[$type] ?? $type;

        // Reuse accountStatement logic (الفلترة تتم داخله الآن)
        $request->merge(['from' => $from, 'to' => $to, 'type' => $type, 'mode' => $pdfStyle]);
        $jsonResponse = $this->accountStatement($request, $employee);
        $data = $jsonResponse->getData(true);

        if (!$data['success']) {
            return $jsonResponse;
        }

        $allEntries = $data['data']['all_lines'] ?? [];

        if ($pdfStyle === 'detailed') {
            // STEP 1: اشتقاق order_id من source_type/source_id أولاً
            foreach ($allEntries as &$entry) {
                $orderId = $entry['order_id'] ?? null;
                if (!$orderId && !empty($entry['source_type']) && $entry['source_type'] === Order::class && !empty($entry['source_id'])) {
                    $orderId = (int) $entry['source_id'];
                    $entry['order_id'] = $orderId;
                }
            }
            unset($entry);

            // STEP 2: جمع orderIds الآن بعد الاشتقاق
            $orderIds = [];
            $invoiceNumbersFromDescriptions = [];
            foreach ($allEntries as $entry) {
                if (!empty($entry['order_id'])) {
                    $orderIds[] = $entry['order_id'];
                }
                $desc = $entry['description'] ?? '';
                if (preg_match('/فاتورة\s*-?\s*(\d{8})-(\d{4})INV/i', $desc, $m)) {
                    $invoiceNumbersFromDescriptions[] = 'INV-' . $m[1] . '-' . $m[2];
                }
            }
            $orderIds = array_unique($orderIds);
            $invoiceNumbersFromDescriptions = array_unique($invoiceNumbersFromDescriptions);

            // STEP 3: استعلام Invoices/Orders باستخدام orderIds الممتلئة
            $invoicesByOrderId = [];
            $invoicesByNumber = [];
            $paymentsByInvoiceId = [];
            $transactionsByOrderId = [];

            $ordersById = [];

            if (!empty($orderIds)) {
                $invoices = Invoice::with(['items', 'payments', 'order.branch', 'order.cashier'])
                    ->whereIn('order_id', $orderIds)
                    ->get()
                    ->keyBy('order_id');

                foreach ($invoices as $orderId => $invoice) {
                    $invoicesByOrderId[$orderId] = $invoice;
                    $paymentsByInvoiceId[$invoice->id] = $invoice->payments ?? collect();
                }

                $ordersById = Order::with(['items.department', 'branch:id,name', 'cashier:id,name'])
                    ->whereIn('id', $orderIds)
                    ->get()
                    ->keyBy('id');

                $transactions = Transaction::with(['entries.account', 'entries.costCenter'])
                    ->where('source_type', Order::class)
                    ->whereIn('source_id', $orderIds)
                    ->where('type', 'sale')
                    ->get()
                    ->keyBy('source_id');

                foreach ($transactions as $orderId => $transaction) {
                    $transactionsByOrderId[$orderId] = $transaction;
                }
            }

            if (!empty($invoiceNumbersFromDescriptions)) {
                $invoicesByNumber = Invoice::with(['items', 'payments', 'order.branch', 'order.cashier'])
                    ->whereIn('number', $invoiceNumbersFromDescriptions)
                    ->get()
                    ->keyBy('number');
            }

            // STEP 4: تحضير salesByOrderId (من buildSalesLines — قد تكون فارغة)
            $salesLines = $data['data']['accounts']['sales']['lines'] ?? [];
            $salesByOrderId = [];
            foreach ($salesLines as $sl) {
                if (!empty($sl['order_id'])) {
                    $salesByOrderId[$sl['order_id']] = $sl;
                }
            }

            // STEP 5: الإثراء الفعلي — القواميس الآن ممتلئة
            foreach ($allEntries as &$entry) {
                $orderId = $entry['order_id'] ?? null;

                if ($orderId && isset($salesByOrderId[$orderId])) {
                    $entry['items'] = $salesByOrderId[$orderId]['items'] ?? [];
                    $entry['has_discounts'] = $salesByOrderId[$orderId]['has_discounts'] ?? false;
                    $entry['total_items'] = $salesByOrderId[$orderId]['total_items'] ?? 0;
                    $entry['total_discount_amount'] = $salesByOrderId[$orderId]['total_discount_amount'] ?? 0;
                    $entry['discount_count'] = $salesByOrderId[$orderId]['discount_count'] ?? 0;
                }

                if ($orderId && isset($invoicesByOrderId[$orderId])) {
                    $invoice = $invoicesByOrderId[$orderId];

                    if (empty($entry['items']) || count($entry['items']) === 0) {
                        $entry['items'] = $invoice->items->map(fn($ii) => [
                            'product_name'            => $ii->item_name,
                            'product_name_ar'         => $ii->item_name_ar ?? $ii->item_name,
                            'quantity'                => (float) $ii->quantity,
                            'unit_price'              => (float) $ii->price,
                            'total'                   => (float) $ii->total,
                            'discount_amount'         => (float) ($ii->discount_amount ?? 0),
                            'discount_percent'        => (float) ($ii->discount_percent ?? 0),
                            'discount_apply_strategy' => $ii->discount_apply_strategy ?? null,
                            'discount_type'           => ($ii->discount_percent ?? 0) > 0 ? 'percent' : (($ii->discount_amount ?? 0) > 0 ? 'amount' : null),
                            'tax_rate'                => (float) ($ii->tax_rate ?? 0),
                            'tax_amount'              => (float) ($ii->tax_amount ?? 0),
                            'department'              => $ii->department?->name ?? null,
                            'item_code'               => $ii->item_id,
                            'item_id'                 => $ii->item_id,
                            'barcode'                 => $ii->barcode ?? null,
                        ])->all();
                        if (empty($entry['has_discounts'])) {
                            $entry['has_discounts'] = (float) $invoice->discount > 0;
                        }
                    }

                    $entry['invoice_details'] = [
                        'invoice_number' => $invoice->number,
                        'invoice_status' => $invoice->status,
                        'invoice_date'   => $invoice->invoice_date?->format('Y-m-d'),
                        'subtotal'       => (float) $invoice->subtotal,
                        'discount'       => (float) $invoice->discount,
                        'total'          => (float) $invoice->total,
                        'payment_method' => $invoice->payment_method,
                        'notes'          => $invoice->notes,
                        'customer_name'  => $invoice->order->customer_name ?? null,
                        'customer_phone' => $invoice->order->customer_phone ?? null,
                        'table_number'   => $invoice->order->table_number ?? null,
                        'cashier_name'   => $invoice->order->cashier->name ?? null,
                        'branch_name'    => $invoice->order->branch->name ?? null,
                        'order_number'   => $invoice->order->order_number ?? null,
                        'order_type'     => $invoice->order->order_type ?? null,
                        'paid_amount'    => (float) $invoice->payments()->sum('amount'),
                        'remaining'      => max(0, (float) $invoice->total - (float) $invoice->payments()->sum('amount')),
                    ];

                    if (!empty($entry['items'])) {
                        $invoiceItems = $invoice->items ?? collect();
                        foreach ($entry['items'] as &$item) {
                            $invItem = $invoiceItems->first(fn($ii) => $ii->item_name === ($item['product_name'] ?? $item['item_name'] ?? ''));
                            if ($invItem) {
                                $item['item_code'] = $invItem->item_id;
                                $item['item_id'] = $invItem->item_id;
                                $item['discount_name'] = $invItem->discount_id ? ('خصم #' . $invItem->discount_id) : null;
                                $item['discount_apply_strategy'] = $invItem->discount_apply_strategy;
                                $item['line_net'] = (float) ($invItem->total ?? $item['total']);
                            }
                        }
                        unset($item);
                    }
                } elseif ($orderId && isset($ordersById[$orderId]) && (empty($entry['items']) || count($entry['items']) === 0)) {
                    $order = $ordersById[$orderId];
                    $entry['items'] = $order->items
                        ->filter(fn($i) => $i->status !== 'cancelled')
                        ->values()
                        ->map(fn($i) => [
                            'product_name'            => $i->item_name,
                            'product_name_ar'         => $i->item_name_ar ?? $i->item_name,
                            'quantity'                => (float) $i->quantity,
                            'unit_price'              => (float) $i->price,
                            'total'                   => (float) $i->total,
                            'discount_amount'         => (float) ($i->discount_amount ?? 0),
                            'discount_percent'        => (float) ($i->discount_percent ?? 0),
                            'discount_apply_strategy' => $i->discount_apply_strategy ?? null,
                            'tax_rate'                => (float) ($i->tax_rate ?? 0),
                            'tax_amount'              => (float) ($i->tax_amount ?? 0),
                            'department'              => $i->department?->name ?? null,
                            'barcode'                 => $i->barcode ?? null,
                        ])->all();
                }

                // NEW: For entries without order_id but with invoice reference in description (advance/salary/loan entries)
                if (!$orderId && empty($entry['items'])) {
                    $desc = $entry['description'] ?? '';
                    if (preg_match('/فاتورة\s*-?\s*(\d{8})-(\d{4})INV/i', $desc, $m)) {
                        $invNumber = 'INV-' . $m[1] . '-' . $m[2];
                        if (isset($invoicesByNumber[$invNumber])) {
                            $invoice = $invoicesByNumber[$invNumber];
                            $entry['items'] = $invoice->items->map(fn($ii) => [
                                'product_name'            => $ii->item_name,
                                'product_name_ar'         => $ii->item_name_ar ?? $ii->item_name,
                                'quantity'                => (float) $ii->quantity,
                                'unit_price'              => (float) $ii->price,
                                'total'                   => (float) $ii->total,
                                'discount_amount'         => (float) ($ii->discount_amount ?? 0),
                                'discount_percent'        => (float) ($ii->discount_percent ?? 0),
                                'discount_apply_strategy' => $ii->discount_apply_strategy ?? null,
                                'discount_type'           => ($ii->discount_percent ?? 0) > 0 ? 'percent' : (($ii->discount_amount ?? 0) > 0 ? 'amount' : null),
                                'tax_rate'                => (float) ($ii->tax_rate ?? 0),
                                'tax_amount'              => (float) ($ii->tax_amount ?? 0),
                                'department'              => $ii->department?->name ?? null,
                                'item_code'               => $ii->item_id,
                                'item_id'                 => $ii->item_id,
                                'barcode'                 => $ii->barcode ?? null,
                            ])->all();
                            $entry['has_discounts'] = (float) $invoice->discount > 0;
                            $entry['invoice_details'] = [
                                'invoice_number' => $invoice->number,
                                'invoice_status' => $invoice->status,
                                'invoice_date'   => $invoice->invoice_date?->format('Y-m-d'),
                                'subtotal'       => (float) $invoice->subtotal,
                                'discount'       => (float) $invoice->discount,
                                'total'          => (float) $invoice->total,
                                'payment_method' => $invoice->payment_method,
                                'notes'          => $invoice->notes,
                                'customer_name'  => $invoice->order->customer_name ?? null,
                                'customer_phone' => $invoice->order->customer_phone ?? null,
                                'table_number'   => $invoice->order->table_number ?? null,
                                'cashier_name'   => $invoice->order->cashier->name ?? null,
                                'branch_name'    => $invoice->order->branch->name ?? null,
                                'order_number'   => $invoice->order->order_number ?? null,
                                'order_type'     => $invoice->order->order_type ?? null,
                                'paid_amount'    => (float) $invoice->payments()->sum('amount'),
                                'remaining'      => max(0, (float) $invoice->total - (float) $invoice->payments()->sum('amount')),
                            ];
                        }
                    }
                }

                if ($orderId && isset($invoicesByOrderId[$orderId])) {
                    $invId = $invoicesByOrderId[$orderId]->id;
                    $entry['payments_data'] = isset($paymentsByInvoiceId[$invId])
                        ? $paymentsByInvoiceId[$invId]->map(fn($p) => [
                            'method'           => $p->payment_method ?? $p->method,
                            'amount'           => (float) $p->amount,
                            'reference_number' => $p->reference_number,
                            'paid_at'          => $p->created_at?->format('Y-m-d H:i'),
                        ])->toArray()
                        : [];
                } else {
                    $entry['payments_data'] = [];
                }

                if ($orderId && isset($transactionsByOrderId[$orderId])) {
                    $transaction = $transactionsByOrderId[$orderId];
                    $entry['journal_entries'] = $transaction->entries->map(fn($e) => [
                        'account_code' => $e->account?->code,
                        'account_name' => $e->account?->name,
                        'debit'        => (float) $e->debit,
                        'credit'       => (float) $e->credit,
                        'description'  => $e->description,
                        'cost_center'  => $e->costCenter?->name,
                    ])->toArray();
                    $entry['journal_number'] = $transaction->number;
                    $entry['journal_status'] = $transaction->status;
                } else {
                    $entry['journal_entries'] = [];
                }
            }
            unset($entry);
        }

        $totals = $data['data']['totals'] ?? ['total_debit' => 0, 'total_credit' => 0, 'opening_balance' => 0, 'closing_balance' => 0];
        $totalDebit = $totals['total_debit'];
        $totalCredit = $totals['total_credit'];
        $closingBalance = $totals['closing_balance'] ?? 0;
        $openingBalance = $totals['opening_balance'] ?? 0;
        $openingEntries = [];

        // Define variables for mPDF header/footer (must be in controller scope, not just view)
        $companyName = 'شركة O2';
        $companyLocation = 'فلسطين';
        $currency = 'شيكل';
        $printedBy = $request->user()->name ?? 'غير معروف';
        $printedAt = now()->format('Y-m-d H:i:s');
        $erpName = 'O2 ERP System';

        $html = view('pdf.employee-statement', [
            'employeeName'       => $data['data']['employee']['name'] ?? $employee->name,
            'employeeId'         => $data['data']['employee']['id'] ?? $employee->id,
            'fromDate'           => $from,
            'toDate'             => $to,
            'statementTypeLabel' => $statementTypeLabel,
            'entries'            => $allEntries,
            'openingEntries'     => $openingEntries,
            'openingBalance'     => $openingBalance,
            'closingBalance'     => $closingBalance,
            'totalDebit'         => $totalDebit,
            'totalCredit'        => $totalCredit,
            'pdfStyle'           => $pdfStyle,
            'companyName'        => $companyName,
            'companyLocation'    => $companyLocation,
            'currency'           => $currency,
            'printedBy'          => $printedBy,
            'printedAt'          => $printedAt,
            'erpName'            => $erpName,
        ])->render();

        $mpdf = new Mpdf([
            'mode'                => 'ar',
            'autoLangToFont'      => true,
            'autoArabic'          => true,
            'format'              => 'A4',
            'orientation'         => 'P',
            'margin_left'         => 15,
            'margin_right'        => 15,
            'margin_top'          => 15,
            'margin_bottom'       => 20,
            'margin_header'       => 10,
            'margin_footer'       => 10,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        // Set header and footer
        $mpdf->SetHTMLHeader('<div style="text-align:center;font-size:7pt;color:#999;border-bottom:0.5px solid #ddd;padding-bottom:3px;">' . ($companyName ?? 'شركة O2') . ' — كشف حساب ' . $statementTypeLabel . '</div>');
        $mpdf->SetHTMLFooter('<div style="font-size:7pt;color:#999;border-top:0.5px solid #ddd;padding-top:3px;display:flex;justify-content:space-between;"><span>Print Date: ' . ($printedAt ?? now()->format('Y-m-d H:i:s')) . '</span><span>Page {PAGENO} of {nbpg}</span></div>');

        $mpdf->WriteHTML($html);
        $filename = "كشف_حساب_{$employee->name}_{$from}_$to.pdf";
        return response($mpdf->Output($filename, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
