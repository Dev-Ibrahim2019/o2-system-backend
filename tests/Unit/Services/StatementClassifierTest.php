<?php

namespace Tests\Unit\Services;

use App\Services\Accounting\StatementClassifier;
use PHPUnit\Framework\TestCase;

class StatementClassifierTest extends TestCase
{
    public function test_sales_invoice_on_advance_account_classifies_as_sales_not_advance(): void
    {
        $line = [
            'type'               => 'sale',
            'source_type'        => 'App\Models\Order',
            'source_label'       => 'Order',
            'transaction_number' => 'ORD-1001',
            'debit'              => 300,
            'credit'             => 0,
        ];

        $classified = StatementClassifier::classifyLine($line);

        $this->assertSame('sales', $classified['movement_type']);
        $this->assertSame('مبيعات موظف', $classified['movement_label']);
    }

    public function test_cash_advance_classifies_as_advance(): void
    {
        $line = [
            'type'               => 'payment',
            'source_type'        => 'App\Models\Employee',
            'transaction_number' => 'ADV-20260630-0001',
            'debit'              => 500,
            'credit'             => 0,
        ];

        $classified = StatementClassifier::classifyLine($line);

        $this->assertSame('advance', $classified['movement_type']);
    }

    public function test_sales_filter_keeps_only_sales(): void
    {
        $lines = [
            ['movement_type' => 'sales', 'debit' => 0, 'credit' => 300, 'date' => '2026-06-01'],
            ['movement_type' => 'advance', 'debit' => 500, 'credit' => 0, 'date' => '2026-06-02'],
        ];

        $filtered = StatementClassifier::filterByType($lines, 'sales');

        $this->assertCount(1, $filtered);
        $this->assertSame('sales', $filtered[0]['movement_type']);
    }

    public function test_advance_filter_excludes_sales(): void
    {
        $lines = [
            ['movement_type' => 'sales', 'debit' => 0, 'credit' => 300, 'date' => '2026-06-01'],
            ['movement_type' => 'advance', 'debit' => 500, 'credit' => 0, 'date' => '2026-06-02'],
        ];

        $filtered = StatementClassifier::filterByType($lines, 'advance');

        $this->assertCount(1, $filtered);
        $this->assertSame('advance', $filtered[0]['movement_type']);
    }

    public function test_payment_filter_excludes_advance_and_sales(): void
    {
        $lines = [
            ['movement_type' => 'sales', 'credit' => 300, 'debit' => 0, 'date' => '2026-06-01'],
            ['movement_type' => 'advance', 'debit' => 500, 'credit' => 0, 'date' => '2026-06-02'],
            ['movement_type' => 'payment', 'debit' => 0, 'credit' => 100, 'date' => '2026-06-03'],
        ];

        $filtered = StatementClassifier::filterByType($lines, 'payment');

        $this->assertCount(1, $filtered);
        $this->assertSame('payment', $filtered[0]['movement_type']);
    }

    public function test_deduplicate_removes_duplicate_order_lines(): void
    {
        $lines = [
            ['source_type' => 'App\Models\Order', 'source_id' => 10, 'movement_type' => 'sales'],
            ['source_type' => 'App\Models\Order', 'source_id' => 10, 'movement_type' => 'sales'],
        ];

        $deduped = StatementClassifier::deduplicateLines($lines);

        $this->assertCount(1, $deduped);
    }

    public function test_running_balance_is_recomputed(): void
    {
        $lines = [
            ['date' => '2026-06-01', 'debit' => 500, 'credit' => 0],
            ['date' => '2026-06-02', 'debit' => 0, 'credit' => 300],
        ];

        $result = StatementClassifier::computeRunningBalances($lines, 0);

        $this->assertSame(-500.0, $result['lines'][0]['running_balance']);
        $this->assertSame(-200.0, $result['lines'][1]['running_balance']);
        $this->assertSame(-200.0, $result['closing_balance']);
    }
}
