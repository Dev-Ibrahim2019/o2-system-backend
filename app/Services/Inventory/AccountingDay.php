<?php

namespace App\Services\Inventory;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * اليوم المحاسبي: من 02:00 حتى 02:00 من اليوم التالي، بتوقيت الفروع (القرار 14).
 *
 * لا توجد في O2 دالة لليوم المحاسبي (تقرير المرحلة 0)، فهذه هي النقطة الوحيدة لحسابه
 * في الوحدة. منطق خالص بلا قاعدة بيانات — يُختبر منفصلاً.
 *
 * القاعدة: اللحظة تتبع اليوم التقويمي المحلي نفسه إن كانت عند ساعة البداية أو بعدها،
 * وتتبع اليوم السابق إن كانت قبلها.
 * مثال: 2026-10-20 01:30 بتوقيت غزة (قبل 02:00) → اليوم المحاسبي 2026-10-19.
 *       2026-10-20 02:00 بتوقيت غزة → اليوم المحاسبي 2026-10-20.
 */
final class AccountingDay
{
    private int $startHour;

    private int $startMinute;

    public function __construct(private string $timezone, string $dayStart)
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $dayStart, $m)) {
            throw new InvalidArgumentException('صيغة بداية اليوم المحاسبي يجب أن تكون HH:MM.');
        }
        $this->startHour = (int) $m[1];
        $this->startMinute = (int) $m[2];
    }

    public static function fromConfig(): self
    {
        return new self(config('inventory.business_timezone'), config('inventory.accounting_day_start'));
    }

    /** اليوم المحاسبي (Y-m-d) الذي تقع فيه لحظة ما. */
    public function of(CarbonInterface $moment): string
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);
        $minuteOfDay = $local->hour * 60 + $local->minute;

        return $minuteOfDay < $this->startHour * 60 + $this->startMinute
            ? $local->subDay()->toDateString()
            : $local->toDateString();
    }

    /**
     * حدود اليوم المحاسبي بتوقيت UTC: [البداية شاملة، النهاية غير شاملة).
     * البداية والنهاية تُضبطان على ساعة الحائط المحلية (لا بإضافة 24 ساعة)، حتى يكون
     * يوم تغيير التوقيت الصيفي صحيحاً (23 أو 25 ساعة).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(string $day): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone);
        if ($date === false || $date->toDateString() !== $day) {
            throw new InvalidArgumentException('تاريخ اليوم المحاسبي غير صالح.');
        }
        $start = $date->setTime($this->startHour, $this->startMinute);
        $end = $date->addDay()->setTime($this->startHour, $this->startMinute);

        return [$start->utc(), $end->utc()];
    }
}
