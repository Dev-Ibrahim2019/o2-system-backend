<?php

namespace Tests\Unit\Services;

use App\Services\Accounting\JournalEntryValidationService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class JournalEntryValidationServiceTest extends TestCase
{
    private JournalEntryValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new JournalEntryValidationService();
    }

    #[Test]
    public function it_passes_balanced_entries(): void
    {
        $this->validator->assertBalanced([
            ['debit' => 100, 'credit' => 0],
            ['debit' => 0, 'credit' => 100],
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function it_throws_on_unbalanced_entries(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('غير متوازن');

        $this->validator->assertBalanced([
            ['debit' => 80, 'credit' => 0],
            ['debit' => 0, 'credit' => 100],
        ]);
    }

    #[Test]
    public function it_validates_discount_scenario_a_cash_sale(): void
    {
        $this->validator->assertBalanced([
            ['debit' => 80, 'credit' => 0],
            ['debit' => 20, 'credit' => 0],
            ['debit' => 0, 'credit' => 100],
        ], 'سيناريو أ — بيع نقدي بخصم');
    }

    #[Test]
    public function it_validates_discount_scenario_b_employee_advance(): void
    {
        $this->validator->assertBalanced([
            ['debit' => 80, 'credit' => 0],
            ['debit' => 20, 'credit' => 0],
            ['debit' => 0, 'credit' => 100],
        ], 'سيناريو ب — سلفة موظف');
    }

    #[Test]
    public function it_validates_discount_scenario_c_receivable(): void
    {
        $this->validator->assertBalanced([
            ['debit' => 80, 'credit' => 0],
            ['debit' => 20, 'credit' => 0],
            ['debit' => 0, 'credit' => 100],
        ], 'سيناريو ج — ذمم مدينة');
    }
}
