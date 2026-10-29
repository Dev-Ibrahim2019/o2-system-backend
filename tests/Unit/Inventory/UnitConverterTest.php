<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\CategoryTree;
use App\Services\Inventory\UnitConverter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UnitConverterTest extends TestCase
{
    #[Test]
    public function converts_input_unit_to_base_unit(): void
    {
        // 2.5 كيلو → 2500 غرام
        $this->assertSame('2500.000000', UnitConverter::toBase('2.5', '1000'));
        // كرتونة فيها 24 علبة
        $this->assertSame('72.000000', UnitConverter::toBase(3, 24));
    }

    #[Test]
    public function converts_fractional_sales_quantity_without_float_drift(): void
    {
        // كمية بيع بالوزن 0.077 كغ إلى غرام
        $this->assertSame('77.000000', UnitConverter::toBase('0.077', '1000'));
        $this->assertSame('77.000000', UnitConverter::toBase(0.077, 1000));
        $this->assertSame('0.300000', UnitConverter::toBase('0.3', '1'));
    }

    #[Test]
    public function converts_base_quantity_back_to_unit(): void
    {
        $this->assertSame('2.500000', UnitConverter::fromBase('2500', '1000'));
        // 1 ÷ 3 تُقرَّب إلى 6 خانات
        $this->assertSame('0.333333', UnitConverter::fromBase('1', '3'));
        $this->assertSame('0.666667', UnitConverter::fromBase('2', '3'));
    }

    #[Test]
    public function rounds_half_away_from_zero(): void
    {
        $this->assertSame('1.000001', UnitConverter::round('1.0000005'));
        $this->assertSame('-1.000001', UnitConverter::round('-1.0000005'));
        $this->assertSame('1.000000', UnitConverter::round('1.0000004'));
    }

    #[Test]
    public function rejects_zero_or_negative_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UnitConverter::toBase('1', '0');
    }

    #[Test]
    public function rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UnitConverter::toBase('abc', '1');
    }

    #[Test]
    public function category_tree_detects_cycles(): void
    {
        // 1 ← 2 ← 3 (3 تابع لـ 2، و2 تابع لـ 1)
        $parents = [1 => null, 2 => 1, 3 => 2, 4 => null];
        $parentOf = fn (int $id) => $parents[$id] ?? null;

        $this->assertTrue(CategoryTree::wouldCreateCycle(1, 3, $parentOf), 'جعل الجد تابعاً لحفيده');
        $this->assertTrue(CategoryTree::wouldCreateCycle(2, 2, $parentOf), 'جعل التصنيف تابعاً لنفسه');
        $this->assertFalse(CategoryTree::wouldCreateCycle(3, 4, $parentOf));
        $this->assertFalse(CategoryTree::wouldCreateCycle(3, null, $parentOf));
    }
}
