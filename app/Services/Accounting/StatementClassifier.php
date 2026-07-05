<?php

namespace App\Services\Accounting;

/**
 * محرك تصنيف حركات كشف الحساب الموحد.
 * لا يعتمد على account_id أو account_name — فقط بيانات المستند والقيد.
 */
class StatementClassifier
{
    public const MOVEMENT_SALES              = 'sales';
    public const MOVEMENT_ADVANCE            = 'advance';
    public const MOVEMENT_ADVANCE_REPAYMENT  = 'advance_repayment';
    public const MOVEMENT_LOAN               = 'loan';
    public const MOVEMENT_LOAN_REPAYMENT     = 'loan_repayment';
    public const MOVEMENT_SALARY             = 'salary';
    public const MOVEMENT_SALARY_PAYMENT     = 'salary_payment';
    public const MOVEMENT_PAYMENT            = 'payment';
    public const MOVEMENT_PURCHASE           = 'purchase';
    public const MOVEMENT_TRANSFER           = 'transfer';
    public const MOVEMENT_JOURNAL            = 'journal';
    public const MOVEMENT_RETURN             = 'return';
    public const MOVEMENT_SETTLEMENT         = 'settlement';
    public const MOVEMENT_ADJUSTMENT         = 'adjustment';
    public const MOVEMENT_OPENING            = 'opening';
    public const MOVEMENT_CLOSING            = 'closing';
    public const MOVEMENT_OTHER              = 'other';

    public const MOVEMENT_CASH_WITHDRAWAL  = 'cash_withdrawal';
    public const MOVEMENT_CASH_DEPOSIT     = 'cash_deposit';
    public const MOVEMENT_REFUND           = 'refund';
    public const MOVEMENT_DISCOUNT         = 'discount';
    public const MOVEMENT_INVENTORY        = 'inventory';
    public const MOVEMENT_EXPENSE          = 'expense';
    public const MOVEMENT_CUSTOMER_PAYMENT = 'customer_payment';
    public const MOVEMENT_SUPPLIER_PAYMENT = 'supplier_payment';

    private const META = [
        self::MOVEMENT_SALES             => ['label' => 'مبيعات',          'doc' => 'فاتورة مبيعات'],
        self::MOVEMENT_ADVANCE           => ['label' => 'سلفة نقدية',      'doc' => 'سند صرف'],
        self::MOVEMENT_ADVANCE_REPAYMENT => ['label' => 'سداد سلفة',       'doc' => 'سند قبض'],
        self::MOVEMENT_LOAN              => ['label' => 'قرض',             'doc' => 'سند قرض'],
        self::MOVEMENT_LOAN_REPAYMENT    => ['label' => 'سداد قرض',        'doc' => 'سند قبض'],
        self::MOVEMENT_SALARY            => ['label' => 'استحقاق راتب',    'doc' => 'سند راتب'],
        self::MOVEMENT_SALARY_PAYMENT    => ['label' => 'صرف راتب',        'doc' => 'سند صرف راتب'],
        self::MOVEMENT_PAYMENT           => ['label' => 'دفعة',            'doc' => 'سند دفع'],
        self::MOVEMENT_PURCHASE          => ['label' => 'مشتريات',         'doc' => 'فاتورة شراء'],
        self::MOVEMENT_TRANSFER          => ['label' => 'تحويل',           'doc' => 'سند تحويل'],
        self::MOVEMENT_JOURNAL           => ['label' => 'قيد يومية',       'doc' => 'قيد يومية'],
        self::MOVEMENT_RETURN            => ['label' => 'مرتجع',           'doc' => 'مرتجع مبيعات'],
        self::MOVEMENT_SETTLEMENT        => ['label' => 'تسوية',           'doc' => 'سند تسوية'],
        self::MOVEMENT_ADJUSTMENT        => ['label' => 'تعديل',           'doc' => 'إشعار تعديل'],
        self::MOVEMENT_OPENING           => ['label' => 'رصيد افتتاحي',    'doc' => 'رصيد افتتاحي'],
        self::MOVEMENT_CLOSING           => ['label' => 'رصيد ختامي',      'doc' => 'إقفال'],
        self::MOVEMENT_CASH_WITHDRAWAL   => ['label' => 'سحب نقدي',        'doc' => 'سند صرف'],
        self::MOVEMENT_CASH_DEPOSIT      => ['label' => 'إيداع نقدي',      'doc' => 'سند قبض'],
        self::MOVEMENT_REFUND            => ['label' => 'مردودات',         'doc' => 'مردود مبيعات'],
        self::MOVEMENT_DISCOUNT          => ['label' => 'خصم',             'doc' => 'خصم'],
        self::MOVEMENT_INVENTORY         => ['label' => 'مخزون',           'doc' => 'حركة مخزون'],
        self::MOVEMENT_EXPENSE           => ['label' => 'مصروف',           'doc' => 'سند صرف'],
        self::MOVEMENT_CUSTOMER_PAYMENT  => ['label' => 'دفعة عميل',       'doc' => 'سند قبض'],
        self::MOVEMENT_SUPPLIER_PAYMENT  => ['label' => 'دفعة مورد',       'doc' => 'سند صرف'],
        self::MOVEMENT_OTHER             => ['label' => 'أخرى',            'doc' => 'مستند'],
    ];

    /** فلتر API → أنواع الحركة */
    private const FILTER_MAP = [
        'all'        => null,
        'sales'      => [self::MOVEMENT_SALES],
        'advance'    => [self::MOVEMENT_ADVANCE],
        'salary'     => [self::MOVEMENT_SALARY, self::MOVEMENT_SALARY_PAYMENT],
        'loan'       => [self::MOVEMENT_LOAN],
        'payment'    => [self::MOVEMENT_PAYMENT, self::MOVEMENT_ADVANCE_REPAYMENT, self::MOVEMENT_LOAN_REPAYMENT],
        'journal'    => [self::MOVEMENT_JOURNAL],
        'return'     => [self::MOVEMENT_RETURN],
        'settlement' => [self::MOVEMENT_SETTLEMENT],
        'purchase'   => [self::MOVEMENT_PURCHASE],
        'transfer'   => [self::MOVEMENT_TRANSFER],
        'adjustment' => [self::MOVEMENT_ADJUSTMENT],
        'opening'    => [self::MOVEMENT_OPENING],
        'closing'    => [self::MOVEMENT_CLOSING],
    ];

    public static function getAllowedFilters(): array
    {
        return array_keys(self::FILTER_MAP);
    }

    public static function getMeta(string $movementType): array
    {
        return self::META[$movementType] ?? self::META[self::MOVEMENT_OTHER];
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public static function classifyLine(array $line): array
    {
        if (!empty($line['movement_type']) && isset(self::META[$line['movement_type']])) {
            $meta = self::getMeta($line['movement_type']);
            return self::tag($line, $line['movement_type'], $meta['label'], $meta['doc']);
        }

        $txnType     = strtolower((string) ($line['type'] ?? ''));
        $sourceType  = (string) ($line['source_type'] ?? '');
        $sourceLabel = (string) ($line['source_label'] ?? '');
        $reference   = (string) ($line['reference'] ?? '');
        $txnNumber   = strtoupper((string) ($line['transaction_number'] ?? ''));
        $debit       = (float) ($line['debit'] ?? 0);
        $isReversal  = (bool) ($line['is_reversal'] ?? false);

        if ($isReversal || self::isReturnContext($txnType, $reference, $line)) {
            return self::tag($line, self::MOVEMENT_RETURN, 'مرتجع', 'مرتجع مبيعات');
        }

        if (self::isSalesContext($txnType, $sourceType, $sourceLabel, $reference)) {
            return self::tag($line, self::MOVEMENT_SALES, 'مبيعات موظف', 'فاتورة مبيعات');
        }

        if (self::isPurchaseContext($txnType, $sourceType, $sourceLabel)) {
            return self::tag($line, self::MOVEMENT_PURCHASE, 'مشتريات', 'فاتورة شراء');
        }

        if (str_starts_with($txnNumber, 'REPR-') || str_starts_with($txnNumber, 'REPR')) {
            return self::tag($line, self::MOVEMENT_ADVANCE_REPAYMENT, 'سداد سلفة', 'سند قبض');
        }
        if (str_starts_with($txnNumber, 'LNR-') || str_starts_with($txnNumber, 'LNR')) {
            return self::tag($line, self::MOVEMENT_LOAN_REPAYMENT, 'سداد قرض', 'سند قبض');
        }
        if (str_starts_with($txnNumber, 'LN-') || str_starts_with($txnNumber, 'LN')) {
            return self::tag($line, self::MOVEMENT_LOAN, 'قرض', 'سند قرض');
        }
        if (str_starts_with($txnNumber, 'ADV-') || str_starts_with($txnNumber, 'ADV')) {
            return self::tag($line, self::MOVEMENT_ADVANCE, 'سلفة نقدية', 'سند صرف');
        }
        if (str_starts_with($txnNumber, 'SETT-') || str_starts_with($txnNumber, 'SETT')) {
            return self::tag($line, self::MOVEMENT_SETTLEMENT, 'تسوية', 'سند تسوية');
        }

        if ($txnType === 'receipt') {
            return self::tag($line, self::MOVEMENT_PAYMENT, 'دفعة', 'سند قبض');
        }

        if ($txnType === 'payment') {
            return self::tag($line, self::MOVEMENT_ADVANCE, 'سلفة نقدية', 'سند صرف');
        }

        return self::tag($line, ...match ($txnType) {
            'salary'     => self::classifySalaryLine($debit),
            'journal'    => [self::MOVEMENT_JOURNAL, 'قيد يدوي', 'قيد يومية'],
            'settlement' => [self::MOVEMENT_SETTLEMENT, 'تسوية', 'سند تسوية'],
            'opening'    => [self::MOVEMENT_OPENING, 'رصيد افتتاحي', 'رصيد افتتاحي'],
            'adjustment' => [self::MOVEMENT_ADJUSTMENT, 'تسوية', 'إشعار تسوية'],
            'purchase'   => [self::MOVEMENT_PURCHASE, 'مشتريات', 'فاتورة شراء'],
            'expense'    => [self::MOVEMENT_PAYMENT, 'مصروف', 'سند صرف'],
            default      => [self::MOVEMENT_OTHER, 'أخرى', 'مستند'],
        });
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function classifyLines(array $lines): array
    {
        return array_map(fn($line) => self::classifyLine($line), $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function filterByType(array $lines, string $filter): array
    {
        $allowed = self::FILTER_MAP[$filter] ?? null;
        if ($allowed === null) {
            return $lines;
        }

        return array_values(array_filter(
            $lines,
            fn($l) => in_array($l['movement_type'] ?? '', $allowed, true)
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function applyFilters(array $lines, array $filters): array
    {
        $type = $filters['type'] ?? 'all';
        if ($type !== 'all') {
            $lines = self::filterByType($lines, $type);
        }

        if (!empty($filters['branch_id'])) {
            $branchId = (int) $filters['branch_id'];
            $lines = array_values(array_filter(
                $lines,
                fn($l) => (int) ($l['branch_id'] ?? 0) === $branchId
            ));
        }

        if (!empty($filters['document_type'])) {
            $doc = mb_strtolower((string) $filters['document_type']);
            $lines = array_values(array_filter(
                $lines,
                fn($l) => str_contains(mb_strtolower((string) ($l['document_type'] ?? '')), $doc)
            ));
        }

        if (!empty($filters['status'])) {
            $status = mb_strtolower((string) $filters['status']);
            $lines = array_values(array_filter(
                $lines,
                fn($l) => mb_strtolower((string) ($l['status'] ?? 'posted')) === $status
            ));
        }

        if (!empty($filters['amount_from'])) {
            $min = (float) $filters['amount_from'];
            $lines = array_values(array_filter(
                $lines,
                fn($l) => max((float) ($l['debit'] ?? 0), (float) ($l['credit'] ?? 0)) >= $min
            ));
        }

        if (!empty($filters['amount_to'])) {
            $max = (float) $filters['amount_to'];
            $lines = array_values(array_filter(
                $lines,
                fn($l) => max((float) ($l['debit'] ?? 0), (float) ($l['credit'] ?? 0)) <= $max
            ));
        }

        if (!empty($filters['has_discounts']) && filter_var($filters['has_discounts'], FILTER_VALIDATE_BOOLEAN)) {
            $lines = array_values(array_filter(
                $lines,
                fn($l) => !empty($l['has_discounts']) || self::lineHasItemDiscounts($l)
            ));
        }

        foreach (['invoice_number' => 'transaction_number', 'order_number' => 'transaction_number', 'journal_number' => 'transaction_number'] as $param => $field) {
            if (!empty($filters[$param])) {
                $needle = mb_strtolower((string) $filters[$param]);
                $lines = array_values(array_filter(
                    $lines,
                    fn($l) => str_contains(mb_strtolower((string) ($l[$field] ?? '')), $needle)
                ));
            }
        }

        if (!empty($filters['search'])) {
            $lines = self::smartSearch($lines, (string) $filters['search']);
        }

        return $lines;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function smartSearch(array $lines, string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return $lines;
        }

        return array_values(array_filter($lines, function ($line) use ($q) {
            $haystack = implode(' ', array_filter([
                $line['transaction_number'] ?? '',
                $line['reference'] ?? '',
                $line['description'] ?? '',
                $line['movement_label'] ?? '',
                $line['document_type'] ?? '',
                $line['branch_name'] ?? '',
                $line['customer_name'] ?? '',
                $line['employee_name'] ?? '',
                $line['supplier_name'] ?? '',
            ]));

            if (str_contains(mb_strtolower($haystack), $q)) {
                return true;
            }

            foreach ($line['items'] ?? [] as $item) {
                $itemHay = implode(' ', array_filter([
                    $item['product_name'] ?? '',
                    $item['product_name_ar'] ?? '',
                    $item['barcode'] ?? '',
                ]));
                if (str_contains(mb_strtolower($itemHay), $q)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{lines:array<int,array<string,mixed>>,opening_balance:float,closing_balance:float,total_debit:float,total_credit:float}
     */
    public static function computeRunningBalances(array $lines, float $openingBalance = 0): array
    {
        usort($lines, fn($a, $b) => strcmp(
            ($a['date'] ?? '') . ($a['transaction_number'] ?? ''),
            ($b['date'] ?? '') . ($b['transaction_number'] ?? '')
        ));

        $running = $openingBalance;
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as &$line) {
            $debit  = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);
            $totalDebit  += $debit;
            $totalCredit += $credit;
            $running += $credit - $debit;
            $line['running_balance'] = round($running, 3);
        }
        unset($line);

        return [
            'lines'           => $lines,
            'opening_balance' => round($openingBalance, 3),
            'closing_balance' => round($running, 3),
            'total_debit'     => round($totalDebit, 3),
            'total_credit'    => round($totalCredit, 3),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function deduplicateLines(array $lines): array
    {
        $seen = [];
        $result = [];

        foreach ($lines as $line) {
            $key = self::dedupeKey($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function dedupeKey(array $line): string
    {
        if (!empty($line['source_type']) && !empty($line['source_id'])) {
            return $line['source_type'] . ':' . $line['source_id'];
        }

        return ($line['transaction_id'] ?? '') . ':' . ($line['date'] ?? '') . ':' . ($line['debit'] ?? 0) . ':' . ($line['credit'] ?? 0);
    }

    private static function isSalesContext(string $txnType, string $sourceType, string $sourceLabel, string $reference): bool
    {
        if ($txnType === 'sale') {
            return true;
        }
        if (str_contains($sourceType, 'Order') || $sourceLabel === 'Order') {
            return true;
        }
        if (str_contains($sourceType, 'Invoice') && !str_contains($reference, 'PUR')) {
            return true;
        }

        return false;
    }

    private static function isPurchaseContext(string $txnType, string $sourceType, string $sourceLabel): bool
    {
        return $txnType === 'purchase'
            || str_contains($sourceType, 'Purchase')
            || $sourceLabel === 'PurchaseOrder';
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function isReturnContext(string $txnType, string $reference, array $line): bool
    {
        if (($line['movement_type'] ?? '') === self::MOVEMENT_RETURN) {
            return true;
        }
        if (isset($line['total']) && (float) $line['total'] < 0) {
            return true;
        }

        return str_contains(strtolower($reference), 'return')
            || str_contains(strtolower($reference), 'void')
            || ($line['is_reversal'] ?? false);
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function lineHasItemDiscounts(array $line): bool
    {
        foreach ($line['items'] ?? [] as $item) {
            if ((float) ($item['discount_amount'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:string,1:string,2:string} */
    private static function classifySalaryLine(float $debit): array
    {
        if ($debit > 0) {
            return [self::MOVEMENT_SALARY_PAYMENT, 'صرف راتب', 'سند صرف راتب'];
        }

        return [self::MOVEMENT_SALARY, 'خصم راتب', 'سند راتب'];
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private static function tag(array $line, string $type, string $label, string $doc): array
    {
        $line['movement_type']  = $type;
        $line['movement_label'] = $label;
        $line['document_type']  = $doc;

        return $line;
    }
}
