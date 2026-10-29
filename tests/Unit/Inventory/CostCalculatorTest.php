<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\CostCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** المتوسط المرجّح وأثر الحركة على الرصيد — منطق خالص بلا قاعدة بيانات. */
class CostCalculatorTest extends TestCase
{
    public function test_weighted_average_from_brief(): void
    {
        // رصيد 10 كيلو بتكلفة 40، ثم استلام 10 كيلو بتكلفة 50 → المتوسط 45
        $r = CostCalculator::apply('10', '40', '10', '50');

        $this->assertSame('20.000000', $r['quantity']);
        $this->assertSame('45.000000', $r['avg_cost']);
        $this->assertSame('50.000000', $r['unit_cost']);
        $this->assertSame('500.000000', $r['total_cost']);
    }

    public function test_first_incoming_sets_average_to_its_cost(): void
    {
        $r = CostCalculator::apply('0', '0', '15750', '0.04');

        $this->assertSame('15750.000000', $r['quantity']);
        $this->assertSame('0.040000', $r['avg_cost']);
        $this->assertSame('630.000000', $r['total_cost']);
    }

    public function test_incoming_on_negative_balance_takes_incoming_cost(): void
    {
        // القرار 4: رصيد −5 بمتوسط 30، وارد 20 بتكلفة 50 → المتوسط 50
        $r = CostCalculator::apply('-5', '30', '20', '50');

        $this->assertSame('15.000000', $r['quantity']);
        $this->assertSame('50.000000', $r['avg_cost']);
    }

    public function test_outgoing_uses_average_and_keeps_it(): void
    {
        $r = CostCalculator::apply('20', '45', '-3.5', null);

        $this->assertSame('16.500000', $r['quantity']);
        $this->assertSame('45.000000', $r['avg_cost']);
        $this->assertSame('45.000000', $r['unit_cost']);
        $this->assertSame('-157.500000', $r['total_cost']);
    }

    public function test_outgoing_may_go_negative_keeping_average(): void
    {
        $r = CostCalculator::apply('2', '10', '-5', null);

        $this->assertSame('-3.000000', $r['quantity']);
        $this->assertSame('10.000000', $r['avg_cost']);
        $this->assertSame('-50.000000', $r['total_cost']);
    }

    public function test_reversing_an_incoming_at_its_original_cost_restores_previous_average(): void
    {
        // 10 @ 40 ثم وارد 10 @ 50 → 20 @ 45. عكس الوارد (−10 @ 50) يعيد 10 @ 40.
        $r = CostCalculator::apply('20', '45', '-10', '50');

        $this->assertSame('10.000000', $r['quantity']);
        $this->assertSame('40.000000', $r['avg_cost']);
        $this->assertSame('-500.000000', $r['total_cost']);
    }

    public function test_reversing_everything_keeps_average_instead_of_dividing_by_zero(): void
    {
        $r = CostCalculator::apply('10', '40', '-10', '40');

        $this->assertSame('0.000000', $r['quantity']);
        $this->assertSame('40.000000', $r['avg_cost']);
    }

    public function test_repeating_fraction_is_rounded_to_six_places(): void
    {
        // (1 × 1 + 2 × 2) ÷ 3 = 1.666666… → 1.666667
        $r = CostCalculator::apply('1', '1', '2', '2');

        $this->assertSame('1.666667', $r['avg_cost']);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostCalculator::apply('10', '40', '0', '1');
    }

    public function test_incoming_without_cost_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostCalculator::apply('10', '40', '5', null);
    }

    public function test_negative_cost_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostCalculator::apply('10', '40', '5', '-1');
    }
}
