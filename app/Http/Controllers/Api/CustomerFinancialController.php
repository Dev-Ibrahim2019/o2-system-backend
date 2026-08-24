<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Entry;
use App\Services\Accounting\CustomerAccountingService;
use App\Services\Accounting\SubledgerService;
use App\Services\Accounting\StatementExportService;
use App\Services\CustomerIdentityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class CustomerFinancialController extends ApiController
{
    public function __construct(
        private readonly CustomerAccountingService $customerService,
        private readonly SubledgerService $subledgerService,
        private readonly StatementExportService $exportService,
        private readonly CustomerIdentityService $customerIdentity,
    ) {}

    // ──────────────────────────────────────────────────────────
    // CUSTOMER CRUD
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/customers
     */
    public function index(Request $request): JsonResponse
    {
        // Accounting's Customer Accounts screen shows financial customers
        // only — operational (CRM/Call Center) customers are never listed
        // here, even though they exist in the same `customers` table.
        $query = Customer::query()
            ->financial()
            ->with('branch:id,name');

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by risk level
        if ($riskLevel = $request->input('risk_level')) {
            $query->where('risk_level', $riskLevel);
        }

        // Filter by branch
        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        // Sorting
        $sortField = in_array($request->input('sort_by'), ['name', 'code', 'created_at', 'status', 'risk_level'], true)
            ? $request->input('sort_by')
            : 'name';
        $sortDir = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir);

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $customers = $query->paginate($perPage);

        $customerIds = $customers->getCollection()->pluck('id');
        $receivableAccountId = Account::where('code', '1120')->value('id');
        $balances = $receivableAccountId
            ? Entry::query()
                ->join('transactions', 'transactions.id', '=', 'entries.transaction_id')
                ->where('entries.account_id', $receivableAccountId)
                ->where('entries.subledger_type', 'customer')
                ->whereIn('entries.subledger_id', $customerIds)
                ->where('transactions.status', 'posted')
                ->groupBy('entries.subledger_id')
                ->selectRaw('entries.subledger_id, COALESCE(SUM(entries.debit - entries.credit), 0) as balance')
                ->pluck('balance', 'entries.subledger_id')
            : collect();

        $customers->getCollection()->transform(function ($customer) use ($balances) {
            $customer->setBalanceCache((float) ($balances[$customer->id] ?? 0));
            $customer->balance = $customer->balance;
            $customer->available_credit = $customer->available_credit;
            $customer->is_over_limit = $customer->is_over_limit;
            $customer->credit_usage_percent = $customer->credit_usage_percent;
            return $customer;
        });

        return $this->success('تم جلب العملاء', $customers);
    }

    /**
     * POST /api/customers
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        if ($request->filled('category') && $request->input('category') === 'regular') {
            $request->merge(['category' => 'retail']);
        }

        if ($request->filled('payment_terms') && $request->input('payment_terms') === 'due_on_receipt') {
            $request->merge(['payment_terms' => 'immediate']);
        }

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'name_en'        => ['nullable', 'string', 'max:255'],
            'code'           => ['nullable', 'string', 'max:50', 'unique:customers,code'],
            'tax_number'     => ['nullable', 'string', 'max:50'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'mobile'         => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:255'],
            'website'        => ['nullable', 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:500'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            'category'       => ['nullable', 'string', 'in:retail,wholesale,corporate,government,service'],
            'currency'       => ['nullable', 'string', 'max:3'],
            'status'         => ['nullable', 'string', 'in:active,inactive,blocked'],
            'risk_level'     => ['nullable', 'string', 'in:low,medium,high,critical'],
            'credit_limit'   => ['nullable', 'numeric', 'min:0'],
            'payment_terms'  => ['nullable', 'string', 'in:immediate,net15,net30,net60,net90'],
            'credit_days'    => ['nullable', 'integer', 'min:0', 'max:365'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'gps_link'       => ['nullable', 'string', 'max:500'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        // Auto-generate code if not provided
        if (empty($data['code'])) {
            $lastId = Customer::withTrashed()->max('id') ?? 0;
            $data['code'] = 'CUS-' . str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);
        }

        $data['status'] ??= 'active';
        $data['currency'] ??= 'ILS';
        $data['risk_level'] ??= 'low';
        $data['payment_terms'] ??= 'net30';
        $data['credit_days'] ??= 30;

        // This IS the Financial Administration workflow — the only caller
        // allowed to create a financial customer. Not derived from client
        // input: 'customer_type' isn't in the validation rules above, so
        // it can't be overridden by the request even if the client tries.
        $customer = $this->customerIdentity->create($data, Customer::TYPE_FINANCIAL);

        // Post opening balance if set
        if (($data['opening_balance'] ?? 0) > 0) {
            $openingAccount = Account::where('code', '3999')->first();
            if ($openingAccount) {
                $this->customerService->postOpeningBalance(
                    customer: $customer,
                    amount: (float) $data['opening_balance'],
                    openingBalanceAccountId: $openingAccount->id,
                    date: now()->toDateString(),
                    branchId: $data['branch_id'] ?? null,
                );
            }
        }

        $customer->load('branch:id,name');
        $customer->balance = $customer->balance;

        return $this->success('تم إنشاء العميل بنجاح', $customer, 201);
    }

    /**
     * GET /api/customers/{customer}
     */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['branch:id,name', 'salesperson:id,name']);
        $customer->balance = $customer->balance;
        $customer->available_credit = $customer->available_credit;
        $customer->is_over_limit = $customer->is_over_limit;
        $customer->credit_usage_percent = $customer->credit_usage_percent;

        $aging = $this->customerService->getAging($customer);

        return $this->success('كشف حساب العميل', [
            'customer' => $customer,
            'aging'    => $aging,
            'statement_summary' => $this->customerService->getStatement(
                $customer,
                now()->startOfMonth()->toDateString(),
                now()->toDateString(),
            ),
        ]);
    }

    /**
     * PUT /api/customers/{customer}
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        if ($request->filled('category') && $request->input('category') === 'regular') {
            $request->merge(['category' => 'retail']);
        }

        if ($request->filled('payment_terms') && $request->input('payment_terms') === 'due_on_receipt') {
            $request->merge(['payment_terms' => 'immediate']);
        }

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'name_en'        => ['nullable', 'string', 'max:255'],
            'code'           => ['nullable', 'string', 'max:50', 'unique:customers,code,' . $customer->id],
            'tax_number'     => ['nullable', 'string', 'max:50'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'mobile'         => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:255'],
            'website'        => ['nullable', 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:500'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            'category'       => ['nullable', 'string', 'in:retail,wholesale,corporate,government,service'],
            'currency'       => ['nullable', 'string', 'max:3'],
            'status'         => ['nullable', 'string', 'in:active,inactive,blocked'],
            'risk_level'     => ['nullable', 'string', 'in:low,medium,high,critical'],
            'credit_limit'   => ['nullable', 'numeric', 'min:0'],
            'payment_terms'  => ['nullable', 'string', 'in:immediate,net15,net30,net60,net90'],
            'credit_days'    => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'gps_link'       => ['nullable', 'string', 'max:500'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $this->customerIdentity->update($customer, $data);
        $customer->load('branch:id,name');
        $customer->balance = $customer->balance;

        return $this->success('تم تحديث العميل', $customer);
    }

    /**
     * DELETE /api/customers/{customer}
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();
        return $this->success('تم حذف العميل');
    }

    // ──────────────────────────────────────────────────────────
    // ACCOUNTING OPERATIONS
    // ──────────────────────────────────────────────────────────

    /**
     * POST /api/customers/{customer}/invoice
     */
    public function recordInvoice(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount'     => ['required', 'numeric', 'min:0.001'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'date'       => ['required', 'date'],
            'reference'  => ['nullable', 'string', 'max:100'],
            'branch_id'  => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->customerService->recordInvoice(
            customer: $customer,
            amount: $data['amount'],
            taxAmount: $data['tax_amount'] ?? null,
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل فاتورة العميل', [
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ], 201);
    }

    /**
     * POST /api/customers/{customer}/receipt
     */
    public function recordReceipt(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'reference'       => ['nullable', 'string', 'max:100'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->customerService->recordPayment(
            customer: $customer,
            amount: $data['amount'],
            cashAccountId: $data['cash_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل الدفعة', [
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ]);
    }

    /**
     * POST /api/customers/{customer}/credit-note
     */
    public function recordCreditNote(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount'              => ['required', 'numeric', 'min:0.001'],
            'revenue_account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'date'                => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:100'],
            'branch_id'           => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->customerService->recordCreditNote(
            customer: $customer,
            amount: $data['amount'],
            revenueAccountId: $data['revenue_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل إشعار دائن', [
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ]);
    }

    /**
     * POST /api/customers/{customer}/debit-note
     */
    public function recordDebitNote(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount'              => ['required', 'numeric', 'min:0.001'],
            'revenue_account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'date'                => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:100'],
            'branch_id'           => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $transaction = $this->customerService->recordDebitNote(
            customer: $customer,
            amount: $data['amount'],
            revenueAccountId: $data['revenue_account_id'],
            date: $data['date'],
            reference: $data['reference'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return $this->success('تم تسجيل إشعار مدين', [
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ]);
    }

    // ──────────────────────────────────────────────────────────
    // STATEMENTS & REPORTS
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/customers/{customer}/statement
     */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date', 'after_or_equal:from'],
            'type'      => ['nullable', 'string'],
            'mode'      => ['nullable', 'in:simple,detailed'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $statement = $this->customerService->getStatement(
            customer: $customer,
            from: $from,
            to: $to,
            branchId: $data['branch_id'] ?? null,
            type: $data['type'] ?? 'all',
            mode: $data['mode'] ?? 'simple',
        );

        return $this->success("\u{0643}\u{0634}\u{0641}\u{0020}\u{062D}\u{0633}\u{0627}\u{0628}\u{0020}\u{0627}\u{0644}\u{0639}\u{0645}\u{064A}\u{0644}", [
            'customer'  => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
            'balance'   => $customer->balance,
            'period'    => ['from' => $from, 'to' => $to],
            'statement' => $statement,
        ]);
    }

    /**
     * GET /api/customers/{customer}/statement/export
     */
    public function statementExport(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date', 'after_or_equal:from'],
            'type'      => ['nullable', 'string'],
            'mode'      => ['nullable', 'in:simple,detailed'],
            'format'    => ['nullable', 'in:csv,excel'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $statement = $this->customerService->getStatement(
            customer: $customer,
            from: $from,
            to: $to,
            branchId: $data['branch_id'] ?? null,
            type: $data['type'] ?? 'all',
            mode: $data['mode'] ?? 'detailed',
        );

        $payload = $this->buildStatementExportPayload($customer, $statement, $from, $to);
        $base = "statement_customer_{$customer->id}_{$from}_{$to}";

        if (($data['format'] ?? 'csv') === 'excel') {
            return $this->exportService->exportExcelXml($payload, "{$base}.xls");
        }

        return $this->exportService->exportCsv($payload, "{$base}.csv");
    }

    /**
     * GET /api/customers/{customer}/statement/pdf
     */
    public function statementPdf(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date', 'after_or_equal:from'],
            'type'      => ['nullable', 'string'],
            'pdf_style' => ['nullable', 'in:simple,detailed'],
            'mode'      => ['nullable', 'in:simple,detailed'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $pdfStyle = $data['pdf_style'] ?? ($data['mode'] ?? 'detailed');
        $statement = $this->customerService->getStatement(
            customer: $customer,
            from: $from,
            to: $to,
            branchId: $data['branch_id'] ?? null,
            type: $data['type'] ?? 'all',
            mode: $pdfStyle,
        );

        $companyName = "\u{0634}\u{0631}\u{0643}\u{0629}\u{0020}\u{004F}\u{0032}";
        $companyLocation = "\u{0641}\u{0644}\u{0633}\u{0637}\u{064A}\u{0646}";
        $printedAt = now()->format('Y-m-d H:i:s');
        $statementTypeLabel = $this->statementTypeLabel($data['type'] ?? 'all');

        $html = view('pdf.employee-statement', [
            'entityType' => 'customer',
            'entityLabel' => "\u{0627}\u{0644}\u{0639}\u{0645}\u{064A}\u{0644}",
            'entityName' => $customer->name,
            'entityId' => $customer->id,
            'entityCode' => $customer->code,
            'employeeName' => $customer->name,
            'employeeId' => $customer->id,
            'fromDate' => $from,
            'toDate' => $to,
            'statementTypeLabel' => $statementTypeLabel,
            'entries' => $statement['lines'] ?? [],
            'openingEntries' => [],
            'openingBalance' => $statement['opening_balance'] ?? 0,
            'closingBalance' => $statement['closing_balance'] ?? 0,
            'totalDebit' => $statement['total_debit'] ?? 0,
            'totalCredit' => $statement['total_credit'] ?? 0,
            'pdfStyle' => $pdfStyle,
            'companyName' => $companyName,
            'companyLocation' => $companyLocation,
            'currency' => $customer->currency ?? "\u{0634}\u{064A}\u{0643}\u{0644}",
            'printedBy' => $request->user()->name ?? "\u{063A}\u{064A}\u{0631}\u{0020}\u{0645}\u{0639}\u{0631}\u{0648}\u{0641}",
            'printedAt' => $printedAt,
            'erpName' => 'O2 ERP System',
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'ar',
            'autoLangToFont' => true,
            'autoArabic' => true,
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 20,
            'margin_header' => 10,
            'margin_footer' => 10,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetHTMLHeader('<div style="text-align:center;font-size:7pt;color:#999;border-bottom:0.5px solid #ddd;padding-bottom:3px;">' . $companyName . ' - ' . "\u{0643}\u{0634}\u{0641}\u{0020}\u{062D}\u{0633}\u{0627}\u{0628}\u{0020}\u{0627}\u{0644}\u{0639}\u{0645}\u{064A}\u{0644}" . ' ' . $statementTypeLabel . '</div>');
        $mpdf->SetHTMLFooter('<div style="font-size:7pt;color:#999;border-top:0.5px solid #ddd;padding-top:3px;"><span>Print Date: ' . $printedAt . '</span><span style="float:left">Page {PAGENO} of {nbpg}</span></div>');
        $mpdf->WriteHTML($html);

        $filename = "statement_customer_{$customer->id}_{$from}_{$to}.pdf";
        return response($mpdf->Output($filename, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function buildStatementExportPayload(Customer $customer, array $statement, string $from, string $to): array
    {
        return [
            'employee' => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'opening_balance' => $statement['opening_balance'] ?? 0,
                'closing_balance' => $statement['closing_balance'] ?? 0,
                'total_debit' => $statement['total_debit'] ?? 0,
                'total_credit' => $statement['total_credit'] ?? 0,
            ],
            'all_lines' => $statement['lines'] ?? [],
        ];
    }

    private function statementTypeLabel(string $type): string
    {
        return [
            'all' => "\u{0627}\u{0644}\u{0643}\u{0644}",
            'sales' => "\u{0627}\u{0644}\u{0645}\u{0628}\u{064A}\u{0639}\u{0627}\u{062A}",
            'purchases' => "\u{0627}\u{0644}\u{0645}\u{0634}\u{062A}\u{0631}\u{064A}\u{0627}\u{062A}",
            'purchase' => "\u{0627}\u{0644}\u{0645}\u{0634}\u{062A}\u{0631}\u{064A}\u{0627}\u{062A}",
            'payments' => "\u{0627}\u{0644}\u{062F}\u{0641}\u{0639}\u{0627}\u{062A}",
            'payment' => "\u{0627}\u{0644}\u{062F}\u{0641}\u{0639}\u{0627}\u{062A}",
            'receipts' => "\u{0627}\u{0644}\u{062A}\u{062D}\u{0635}\u{064A}\u{0644}\u{0627}\u{062A}",
            'receipt' => "\u{0627}\u{0644}\u{062A}\u{062D}\u{0635}\u{064A}\u{0644}\u{0627}\u{062A}",
            'returns' => "\u{0627}\u{0644}\u{0645}\u{0631}\u{062A}\u{062C}\u{0639}\u{0627}\u{062A}",
            'return' => "\u{0627}\u{0644}\u{0645}\u{0631}\u{062A}\u{062C}\u{0639}\u{0627}\u{062A}",
            'discounts' => "\u{0627}\u{0644}\u{062E}\u{0635}\u{0648}\u{0645}\u{0627}\u{062A}",
            'discount' => "\u{0627}\u{0644}\u{062E}\u{0635}\u{0648}\u{0645}\u{0627}\u{062A}",
            'credit_note' => "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{062F}\u{0627}\u{0626}\u{0646}",
            'debit_note' => "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{0645}\u{062F}\u{064A}\u{0646}",
            'journal' => "\u{0627}\u{0644}\u{0642}\u{064A}\u{0648}\u{062F}",
        ][$type] ?? $type;
    }


    /**
     * GET /api/customers/{customer}/aging
     */
    public function aging(Customer $customer): JsonResponse
    {
        $aging = $this->customerService->getAging($customer);

        return $this->success('تحليل أعمار العميل', [
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'aging'    => $aging,
        ]);
    }

    /**
     * GET /api/customers/{customer}/analytics
     */
    public function analytics(Customer $customer): JsonResponse
    {
        $balance = $this->customerService->getBalance($customer);
        $aging = $this->customerService->getAging($customer);
        $monthlyCollections = $this->customerService->getMonthlyCollections($customer);
        $statement = $this->customerService->getStatement(
            $customer,
            now()->startOfYear()->toDateString(),
            now()->toDateString(),
        );

        // Compute analytics KPIs
        $totalSales = $statement['total_debit'] ?? 0;
        $totalCollected = $statement['total_credit'] ?? 0;
        $collectionRate = $totalSales > 0 ? round(($totalCollected / $totalSales) * 100, 1) : 0;

        // DSO (Days Sales Outstanding)
        $dso = $totalSales > 0
            ? round(($balance / ($totalSales / now()->daysInYear)) * now()->dayOfYear, 1)
            : 0;

        return $this->success('تحليلات العميل', [
            'customer'           => ['id' => $customer->id, 'name' => $customer->name],
            'current_balance'    => $balance,
            'total_sales'        => $totalSales,
            'total_collected'    => $totalCollected,
            'collection_rate'    => $collectionRate,
            'dso'                => $dso,
            'monthly_collections' => $monthlyCollections,
            'aging'              => $aging,
            'credit_usage'       => $customer->credit_usage_percent,
            'available_credit'   => $customer->available_credit,
            'is_over_limit'      => $customer->is_over_limit,
        ]);
    }

    /**
     * GET /api/customers/aging-report
     * تقرير أعمار جميع العملاء
     */
    public function agingReport(Request $request): JsonResponse
    {
        $customers = Customer::financial()->where('status', 'active')->get();
        $report = [];

        foreach ($customers as $customer) {
            $aging = $this->customerService->getAging($customer);
            if ($aging['total'] > 0) {
                $report[] = [
                    'id'      => $customer->id,
                    'name'    => $customer->name,
                    'code'    => $customer->code,
                    'balance' => $customer->balance,
                    'aging'   => $aging,
                ];
            }
        }

        // Totals
        $totals = [
            'current' => collect($report)->sum('aging.current'),
            '1_30'    => collect($report)->sum('aging.1_30'),
            '31_60'   => collect($report)->sum('aging.31_60'),
            '61_90'   => collect($report)->sum('aging.61_90'),
            'over_90' => collect($report)->sum('aging.over_90'),
            'total'   => collect($report)->sum('aging.total'),
        ];

        return $this->success('تقرير أعمار العملاء', [
            'customers' => $report,
            'totals'    => $totals,
        ]);
    }

    /**
     * GET /api/customers/collection-report
     */
    public function collectionReport(Request $request): JsonResponse
    {
        $customers = Customer::financial()->where('status', 'active')->get();
        $report = [];

        foreach ($customers as $customer) {
            $balance = $this->customerService->getBalance($customer);
            if ($balance <= 0) continue;

            $aging = $this->customerService->getAging($customer);
            $monthlyCollections = $this->customerService->getMonthlyCollections($customer);

            // Days past due
            $overdueDays = 0;
            if ($aging['over_90'] > 0) $overdueDays = 90;
            elseif ($aging['61_90'] > 0) $overdueDays = 60;
            elseif ($aging['31_60'] > 0) $overdueDays = 30;
            elseif ($aging['1_30'] > 0) $overdueDays = 15;

            $report[] = [
                'id'                  => $customer->id,
                'name'                => $customer->name,
                'code'                => $customer->code,
                'balance'             => $balance,
                'aging'               => $aging,
                'days_past_due'       => $overdueDays,
                'monthly_collections' => $monthlyCollections,
                'risk_level'          => $customer->risk_level,
                'credit_limit'        => $customer->credit_limit,
                'credit_usage'        => $customer->credit_usage_percent,
                'phone'               => $customer->phone,
            ];
        }

        // Sort by overdue severity
        usort($report, fn($a, $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return $this->success('تقرير التحصيل', [
            'customers' => $report,
            'total_outstanding' => collect($report)->sum('balance'),
            'total_customers'   => count($report),
        ]);
    }
}
