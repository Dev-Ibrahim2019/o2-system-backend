<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\AccountingDay;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** اليوم المحاسبي 02:00 → 02:00 بتوقيت غزة (القرار 14). */
class AccountingDayTest extends TestCase
{
    private function day(): AccountingDay
    {
        return new AccountingDay('Asia/Gaza', '02:00');
    }

    public function test_sale_at_0130_belongs_to_previous_accounting_day(): void
    {
        $this->assertSame('2026-10-19', $this->day()->of(CarbonImmutable::parse('2026-10-20 01:30', 'Asia/Gaza')));
    }

    public function test_day_starts_exactly_at_0200(): void
    {
        $this->assertSame('2026-10-19', $this->day()->of(CarbonImmutable::parse('2026-10-20 01:59:59', 'Asia/Gaza')));
        $this->assertSame('2026-10-20', $this->day()->of(CarbonImmutable::parse('2026-10-20 02:00', 'Asia/Gaza')));
        $this->assertSame('2026-10-20', $this->day()->of(CarbonImmutable::parse('2026-10-20 23:59', 'Asia/Gaza')));
    }

    public function test_utc_moments_are_converted_to_gaza_time_first(): void
    {
        // يناير: غزة = UTC+2. ‏23:30 UTC = 01:30 بتوقيت غزة من اليوم التالي → اليوم المحاسبي نفسه.
        $this->assertSame('2026-01-15', $this->day()->of(CarbonImmutable::parse('2026-01-15 23:30', 'UTC')));
        $this->assertSame('2026-01-16', $this->day()->of(CarbonImmutable::parse('2026-01-16 00:00', 'UTC')));
    }

    public function test_bounds_in_utc(): void
    {
        [$start, $end] = $this->day()->bounds('2026-01-15');

        $this->assertSame('2026-01-15 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-16 00:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezoneName);
    }

    public function test_bounds_round_trip_on_every_day_of_the_year(): void
    {
        // يشمل يومي تغيير التوقيت الصيفي: بداية اليوم تنتمي له، وآخر ثانية قبل نهايته تنتمي له أيضاً.
        $d = CarbonImmutable::parse('2026-01-01');
        for ($i = 0; $i < 365; $i++, $d = $d->addDay()) {
            [$start, $end] = $this->day()->bounds($d->toDateString());
            $this->assertSame($d->toDateString(), $this->day()->of($start));
            $this->assertSame($d->toDateString(), $this->day()->of($end->subSecond()));
            $this->assertSame($d->addDay()->toDateString(), $this->day()->of($end));
        }
    }

    public function test_invalid_start_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AccountingDay('Asia/Gaza', '2am');
    }

    public function test_invalid_day_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->day()->bounds('2026-02-30');
    }
}
