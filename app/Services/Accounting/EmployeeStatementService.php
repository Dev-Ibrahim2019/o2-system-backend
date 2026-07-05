<?php

namespace App\Services\Accounting;

use App\Models\Employee;
use App\Models\Order;
use Carbon\Carbon;

class EmployeeStatementService
{
    public function __construct(
        private readonly SubledgerService $subledgerService,
    ) {}

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function build(Employee $employee, array $filters): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to   = $filters['to']   ?? now()->toDateString();
        $type = $filters['type'] ?? 'all';
        $mode = $filters['mode'] ?? 'detailed';
        $limit = min((int) ($filters['limit'] ?? 500), 1000);
        $cursor = $filters['cursor'] ?? null;

        if (!in_array($type, StatementClassifier::getAllowedFilters(), true)) {
            $type = 'all';
            $filters['type'] = 'all';
        }

        $branchId = !empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;

        $advData  = $this->subledgerService->getStatement('employee', $employee->id, '1130', $from, $to, $branchId);
        $salData  = $this->subledgerService->getStatement('employee', $employee->id, '2120', $from, $to, $branchId);
        $loanData = $this->subledgerService->getStatement('employee', $employee->id, '2130', $from, $to, $branchId);

        $allLines = [];

        foreach ($advData['lines'] as $line) {
            $allLines[] = StatementClassifier::classifyLine($line);
        }
        foreach ($salData['lines'] as $line) {
            $allLines[] = StatementClassifier::classifyLine($line);
        }
        foreach ($loanData['lines'] as $line) {
            $allLines[] = StatementClassifier::classifyLine($line);
        }

        $salesBlock = $this->buildSalesLines($employee, $from, $to, $branchId, $mode);
        $allLines = array_merge($allLines, $salesBlock['lines']);

        $allLines = StatementClassifier::deduplicateLines($allLines);
        $allLines = StatementClassifier::applyFilters($allLines, $filters);

        $openingBalance = ($type === 'all')
            ? round(
                (float) ($advData['opening_balance'] ?? 0)
                    + (float) ($salData['opening_balance'] ?? 0)
                    + (float) ($loanData['opening_balance'] ?? 0),
                3
            )
            : 0.0;

        $computed = StatementClassifier::computeRunningBalances($allLines, $openingBalance);

        if ($type !== 'all' && !empty($computed['lines'])) {
            $first = $computed['lines'][0];
            $derivedOpening = (float) $first['running_balance'] - ((float) ($first['credit'] ?? 0) - (float) ($first['debit'] ?? 0));
            $computed = StatementClassifier::computeRunningBalances($allLines, round($derivedOpening, 3));
        }

        $allLines = $computed['lines'];
        $paginated = $this->paginateLines($allLines, $cursor, $limit);

        if ($mode === 'simple') {
            $paginated['lines'] = array_map(fn($line) => $this->stripLineDetails($line), $paginated['lines']);
        }

        $balances = $this->subledgerService->getEmployeeBalances($employee->id, $to);

        return [
            'employee'            => ['id' => $employee->id, 'name' => $employee->name],
            'period'              => ['from' => $from, 'to' => $to],
            'filter'              => ['type' => $type, 'mode' => $mode],
            'outstanding_advance' => $balances['outstanding_advance'],
            'outstanding_loan'    => $balances['outstanding_loan'] ?? 0,
            'accrued_salary'      => $balances['accrued_salary'],
            'net_payable'         => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
            'accounts'            => [
                'advance' => $advData,
                'salary'  => $salData,
                'loan'    => $loanData,
                'sales'   => $salesBlock,
            ],
            'all_lines'           => $paginated['lines'],
            'pagination'          => [
                'next_cursor' => $paginated['next_cursor'],
                'has_more'    => $paginated['has_more'],
                'total'       => count($allLines),
                'returned'    => count($paginated['lines']),
            ],
            'totals'              => [
                'opening_balance' => $computed['opening_balance'],
                'closing_balance' => $computed['closing_balance'],
                'total_debit'     => $computed['total_debit'],
                'total_credit'    => $computed['total_credit'],
            ],
            'summary_by_movement' => $this->summarizeByMovement($allLines),
        ];
    }

    /**
     * @return array{lines:array<int,array<string,mixed>>,closing_balance:float}
     */
    private function buildSalesLines(
        Employee $employee,
        string $from,
        string $to,
        ?int $branchId,
        string $mode,
    ): array {
        $salesLines = [];
        $runningBalance = 0;

        $query = Order::with(['invoice', 'branch:id,name', 'items.department', 'cashier:id,name'])
            ->where('cashier_id', $employee->id)
            ->whereIn('status', ['paid', 'completed', 'served'])
            ->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()])
            ->orderBy('created_at');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        foreach ($query->get() as $order) {
            $total = (float) $order->total;
            $runningBalance += $total;
            $isReturn = $total < 0;

            $line = [
                'date'               => $order->created_at->format('Y-m-d'),
                'transaction_number' => $order->order_number,
                'transaction_id'     => $order->invoice?->transaction_id ?? null,
                'type'               => 'sale',
                'reference'          => $order->invoice?->number,
                'description'        => "فاتورة مبيعات #{$order->order_number}",
                'debit'              => 0,
                'credit'             => abs($total),
                'balance'            => round($runningBalance, 3),
                'source_type'        => Order::class,
                'source_id'          => $order->id,
                'source_label'       => 'Order',
                'branch_id'          => $order->branch_id,
                'branch_name'        => $order->branch?->name,
                'employee_id'        => $employee->id,
                'employee_name'      => $employee->name,
                'status'             => $order->status,
                'payment_method'     => $order->payment_method ?? null,
                'has_discounts'      => (float) ($order->discount_amount ?? 0) > 0 || (float) ($order->total_discount ?? 0) > 0,
                'invoice_id'         => $order->invoice?->id,
                'invoice_number'     => $order->invoice?->number,
                'order_id'           => $order->id,
                'order_number'       => $order->order_number,
            ];

            if ($mode !== 'simple') {
                $line['items'] = $order->items
                    ->filter(fn($i) => $i->status !== 'cancelled')
                    ->values()
                    ->map(fn($i) => [
                        'product_name'            => $i->item_name,
                        'product_name_ar'         => $i->item_name_ar,
                        'quantity'                => (float) $i->quantity,
                        'unit_price'              => (float) $i->price,
                        'total'                   => (float) $i->total,
                        'discount_amount'         => (float) ($i->discount_amount ?? 0),
                        'discount_percent'        => (float) ($i->discount_percent ?? 0),
                        'discount_apply_strategy' => $i->discount_apply_strategy ?? null,
                        'discount_type'           => ($i->discount_percent ?? 0) > 0 ? 'percent' : (($i->discount_amount ?? 0) > 0 ? 'amount' : null),
                        'tax_rate'                => (float) ($i->tax_rate ?? 0),
                        'tax_amount'              => (float) ($i->tax_amount ?? 0),
                        'department'              => $i->department?->name ?? null,
                        'category'                => null,
                        'warehouse'               => null,
                        'barcode'                 => $i->barcode ?? null,
                    ])
                    ->all();

                // Calculate totals for the invoice
                $totalDiscount = collect($line['items'])->sum('discount_amount');
                $totalItems = collect($line['items'])->sum('quantity');
                $discountCount = collect($line['items'])->filter(fn($i) => ($i['discount_amount'] ?? 0) > 0)->count();
                $line['total_items'] = $totalItems;
                $line['total_discount_amount'] = $totalDiscount;
                $line['discount_count'] = $discountCount;
            }

            if ($isReturn) {
                $line['movement_type'] = StatementClassifier::MOVEMENT_RETURN;
            }

            $salesLines[] = StatementClassifier::classifyLine($line);
        }

        return [
            'lines'           => $salesLines,
            'closing_balance' => round($runningBalance, 3),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{lines:array<int,array<string,mixed>>,next_cursor:?string,has_more:bool}
     */
    private function paginateLines(array $lines, ?string $cursor, int $limit): array
    {
        $start = 0;
        if ($cursor !== null && $cursor !== '') {
            $start = (int) $cursor;
        }

        $slice = array_slice($lines, $start, $limit);
        $next = $start + $limit;
        $hasMore = $next < count($lines);

        return [
            'lines'       => $slice,
            'next_cursor' => $hasMore ? (string) $next : null,
            'has_more'    => $hasMore,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,array{count:int,debit:float,credit:float}>
     */
    private function summarizeByMovement(array $lines): array
    {
        $summary = [];

        foreach ($lines as $line) {
            $type = $line['movement_type'] ?? StatementClassifier::MOVEMENT_OTHER;
            if (!isset($summary[$type])) {
                $meta = StatementClassifier::getMeta($type);
                $summary[$type] = [
                    'movement_type'  => $type,
                    'movement_label' => $meta['label'],
                    'count'          => 0,
                    'debit'          => 0.0,
                    'credit'         => 0.0,
                ];
            }
            $summary[$type]['count']++;
            $summary[$type]['debit']  += (float) ($line['debit'] ?? 0);
            $summary[$type]['credit'] += (float) ($line['credit'] ?? 0);
        }

        foreach ($summary as &$row) {
            $row['debit']  = round($row['debit'], 3);
            $row['credit'] = round($row['credit'], 3);
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function stripLineDetails(array $line): array
    {
        unset($line['items']);

        return $line;
    }
}
